<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'DOJ' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$pageTitle = 'Dispatch SASP — Vue DOJ';
$db = Database::getInstance()->getConnection();

// ── Config dispatch (même que dispatch.php) ─────────────────────────────────
$CODE_TEN = ['10-06'=>'gray','10-07'=>'gray','10-08'=>'gray','10-10'=>'gray','10-23'=>'gray','10-98'=>'gray','10-03'=>'gray','10-38'=>'green','10-50'=>'green','10-51'=>'green','10-52'=>'green','10-56'=>'green','10-57'=>'green','10-35'=>'red','10-31'=>'red','10-91'=>'red','10-40'=>'orange','10-59'=>'orange','10-60'=>'orange','10-74'=>'orange','10-75'=>'orange','10-19'=>'blue','10-14'=>'blue','10-15'=>'blue'];
$OTHER_TAGS = ['En Pause'=>'gray','En Formation'=>'gray','En Déplacement'=>'green','En Attente'=>'purple','En Meeting'=>'yellow','Code 5'=>'red'];
$DISPATCH_THEME = ['bg_type'=>'gradient','bg_color'=>'#060f1d','bg_image'=>'','bg_overlay'=>'0.55','accent'=>'#00e5ff','board_title'=>'DISPATCH BOARD','col_opacity'=>'0.88','card_radius'=>'12','topbar_opacity'=>'0.97','col_blur'=>'8'];
try {
    $cfg = $db->query("SELECT config_key,config_value FROM dispatch_config WHERE config_key IN ('code_ten','other_tags','dispatch_theme')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if(!empty($cfg['code_ten']))       $CODE_TEN       = json_decode($cfg['code_ten'],true)       ?: $CODE_TEN;
    if(!empty($cfg['other_tags']))     $OTHER_TAGS     = json_decode($cfg['other_tags'],true)     ?: $OTHER_TAGS;
    if(!empty($cfg['dispatch_theme'])) $DISPATCH_THEME = array_merge($DISPATCH_THEME, json_decode($cfg['dispatch_theme'],true) ?: []);
} catch(Exception $e){}

// ── AJAX polling READ-ONLY ───────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['action']==='poll_readonly') {
    header('Content-Type: application/json');
    try {
        $lists = $db->query("SELECT * FROM dispatch_lists ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lists as &$list) {
            $s = $db->prepare("
                SELECT c.*,
                    GROUP_CONCAT(DISTINCT CONCAT(l.id,'|',l.name,'|',l.color) SEPARATOR ';;') AS labels_raw,
                    (SELECT COUNT(*) FROM dispatch_checklist_items i JOIN dispatch_checklists cl ON i.checklist_id=cl.id WHERE cl.card_id=c.id) as total_items,
                    (SELECT COUNT(*) FROM dispatch_checklist_items i JOIN dispatch_checklists cl ON i.checklist_id=cl.id WHERE cl.card_id=c.id AND i.is_done=1) as done_items
                FROM dispatch_cards c
                LEFT JOIN dispatch_card_labels cl ON c.id=cl.card_id
                LEFT JOIN dispatch_labels l ON cl.label_id=l.id
                WHERE c.list_id=? GROUP BY c.id ORDER BY c.position
            ");
            $s->execute([$list['id']]);
            $cards = $s->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cards as &$card) {
                $card['labels']         = [];
                if ($card['labels_raw']) foreach(explode(';;',$card['labels_raw']) as $lr){ if(strpos($lr,'|')!==false){[$a,$b,$c]=explode('|',$lr,3);$card['labels'][]=['id'=>$a,'name'=>$b,'color'=>$c];} }
                $card['cover_url']      = $card['cover_image'] ? SITE_URL.'/public/uploads/dispatch/'.$card['cover_image'] : null;
                $card['grade_tags']     = $card['grade_tags']     ? json_decode($card['grade_tags'],true)     : [];
                $card['code_ten_tags']  = $card['code_ten_tags']  ? json_decode($card['code_ten_tags'],true)  : [];
                $card['other_tags']     = $card['other_tags']     ? json_decode($card['other_tags'],true)     : [];
            }
            unset($card);
            $list['cards'] = $cards;
        }
        unset($list);
        echo json_encode(['ok'=>true,'lists'=>$lists,'ts'=>time()]);
    } catch(Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ── Chargement initial ────────────────────────────────────────────────────────
$lists = [];
try {
    $lists = $db->query("SELECT * FROM dispatch_lists ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lists as &$list) {
        $s = $db->prepare("
            SELECT c.*,
                GROUP_CONCAT(DISTINCT CONCAT(l.id,'|',l.name,'|',l.color) SEPARATOR ';;') AS labels_raw
            FROM dispatch_cards c
            LEFT JOIN dispatch_card_labels cl ON c.id=cl.card_id
            LEFT JOIN dispatch_labels l ON cl.label_id=l.id
            WHERE c.list_id=? GROUP BY c.id ORDER BY c.position
        ");
        $s->execute([$list['id']]);
        $cards = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cards as &$card) {
            $card['labels']        = [];
            if ($card['labels_raw']) foreach(explode(';;',$card['labels_raw']) as $lr){ if(strpos($lr,'|')!==false){[$a,$b,$c]=explode('|',$lr,3);$card['labels'][]=['id'=>$a,'name'=>$b,'color'=>$c];} }
            $card['cover_url']     = $card['cover_image'] ? SITE_URL.'/public/uploads/dispatch/'.$card['cover_image'] : null;
            $card['grade_tags']    = $card['grade_tags']    ? json_decode($card['grade_tags'],true)    : [];
            $card['code_ten_tags'] = $card['code_ten_tags'] ? json_decode($card['code_ten_tags'],true) : [];
            $card['other_tags']    = $card['other_tags']    ? json_decode($card['other_tags'],true)    : [];
        }
        unset($card);
        $list['cards'] = $cards;
    }
    unset($list);
} catch(Exception $e){}

include __DIR__ . '/includes/header.php';

$th = $DISPATCH_THEME;
$accent  = preg_match('/^#[0-9a-fA-F]{6}$/', $th['accent']) ? $th['accent'] : '#00e5ff';
$aR=hexdec(substr(ltrim($accent,'#'),0,2));
$aG=hexdec(substr(ltrim($accent,'#'),2,2));
$aB=hexdec(substr(ltrim($accent,'#'),4,2));
$colOp  = max(0,min(1,(float)$th['col_opacity']));
$cardR  = max(4,min(24,(int)$th['card_radius']));
$colBlur= max(0,min(30,(int)$th['col_blur']));
?>
<style>
/* ══ Dispatch View DOJ (read-only) ════════════════════════════════════════ */
:root {
    --dc:<?php echo $accent;?>;
    --dc-bg:rgba(<?php echo "$aR,$aG,$aB";?>,.1);
    --dc-bd:rgba(<?php echo "$aR,$aG,$aB";?>,.14);
    --dc-bdh:rgba(<?php echo "$aR,$aG,$aB";?>,.35);
    --dc-r:<?php echo $cardR;?>px;
}

.dv-outer {
    position:fixed; top:0; left:280px; right:0; bottom:0;
    display:flex; flex-direction:column; overflow:hidden;
    <?php
    if ($th['bg_type']==='image'&&!empty($th['bg_image'])) echo "background:url('".htmlspecialchars($th['bg_image'])."') center/cover no-repeat fixed;";
    elseif ($th['bg_type']==='solid') echo "background:".($th['bg_color']?:'#060f1d').";";
    else echo "background:radial-gradient(ellipse 55% 35% at 10% 55%,rgba($aR,$aG,$aB,.05),transparent),radial-gradient(ellipse 45% 55% at 90% 15%,rgba(0,80,220,.06),transparent),".($th['bg_color']?:'#060f1d').";";
    ?>
}

/* Topbar */
.dv-topbar {
    flex-shrink:0; display:flex; align-items:center; gap:.75rem;
    padding:.7rem 1.35rem; height:58px;
    background:rgba(6,15,29,<?php echo $th['topbar_opacity'];?>);
    border-bottom:1px solid var(--dc-bd); backdrop-filter:blur(14px); z-index:60;
}
.dv-title { font-family:'Orbitron',monospace; font-weight:800; font-size:.88rem; color:var(--dc); letter-spacing:2.5px; text-shadow:0 0 16px rgba(<?php echo "$aR,$aG,$aB";?>,.5); display:flex; align-items:center; gap:.45rem; }
.dv-live-dot { width:8px; height:8px; border-radius:50%; background:var(--dc); box-shadow:0 0 8px var(--dc); animation:live-pulse 2s ease-in-out infinite; }
@keyframes live-pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.7)} }
.dv-readonly-badge {
    padding:.3rem .8rem; border-radius:20px;
    background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.25);
    color:#f87171; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em;
    display:flex; align-items:center; gap:.4rem; margin-left:.5rem;
}
.dv-clock { font-family:'JetBrains Mono',monospace; font-size:.82rem; color:var(--dc); opacity:.7; margin-left:auto; }
.dv-refresh-info { font-size:.7rem; color:#4e6a82; display:flex; align-items:center; gap:.35rem; }

/* Board scroll */
.dv-scroll {
    flex:1; overflow-x:auto; overflow-y:hidden;
    display:flex; gap:.75rem; padding:.85rem;
    scroll-behavior:smooth;
}
.dv-scroll::-webkit-scrollbar { height:6px; }
.dv-scroll::-webkit-scrollbar-track { background:rgba(0,0,0,.2); }
.dv-scroll::-webkit-scrollbar-thumb { background:var(--dc-bd); border-radius:3px; }
.dv-scroll::-webkit-scrollbar-thumb:hover { background:var(--dc-bdh); }

/* Columns */
.dv-col {
    width:290px; flex-shrink:0;
    background:rgba(<?php echo "$aR,$aG,$aB";?>,<?php echo number_format($colOp*.12,2);?>);
    backdrop-filter:blur(<?php echo $colBlur;?>px);
    border:1px solid var(--dc-bd); border-radius:12px;
    display:flex; flex-direction:column; max-height:100%;
}
.dv-col-hd {
    display:flex; align-items:center; gap:.5rem;
    padding:.65rem .9rem; border-bottom:1px solid var(--dc-bd);
    flex-shrink:0;
}
.dv-col-color { width:3px; height:22px; border-radius:2px; flex-shrink:0; }
.dv-col-name { font-family:'Orbitron',monospace; font-size:.72rem; font-weight:700; letter-spacing:1.5px; color:#e2eef8; flex:1; }
.dv-col-count { font-family:'JetBrains Mono',monospace; font-size:.72rem; color:#4e6a82; padding:.1rem .5rem; background:rgba(255,255,255,.04); border-radius:8px; }
.dv-cards { flex:1; overflow-y:auto; padding:.5rem; display:flex; flex-direction:column; gap:.45rem; }
.dv-cards::-webkit-scrollbar { width:3px; }
.dv-cards::-webkit-scrollbar-thumb { background:var(--dc-bd); border-radius:2px; }

/* Cards */
.dv-card {
    background:rgba(11,25,41,<?php echo $colOp;?>);
    border:1px solid var(--dc-bd); border-radius:var(--dc-r);
    padding:.65rem .8rem; cursor:pointer; transition:border-color .18s, box-shadow .18s;
    position:relative;
}
.dv-card:hover { border-color:var(--dc-bdh); box-shadow:0 0 18px rgba(<?php echo "$aR,$aG,$aB";?>,.1); }
.dv-card-cover { width:100%; height:110px; object-fit:cover; border-radius:calc(var(--dc-r) - 4px) calc(var(--dc-r) - 4px) 0 0; margin-bottom:.6rem; margin:-0.65rem -0.8rem 0.6rem; width:calc(100% + 1.6rem); }
.dv-card-title { font-family:'Chakra Petch',sans-serif; font-size:.83rem; font-weight:700; color:#e2eef8; margin-bottom:.5rem; line-height:1.35; }
.dv-card-meta { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; margin-bottom:.45rem; }
.dv-label { padding:.12rem .45rem; border-radius:4px; font-size:.63rem; font-weight:700; }
.dv-prio { display:inline-flex; align-items:center; gap:.28rem; font-size:.68rem; font-weight:700; padding:.18rem .5rem; border-radius:5px; }
.dv-prio.critique { background:rgba(239,68,68,.12); color:#ef4444; border:1px solid rgba(239,68,68,.25); }
.dv-prio.haute    { background:rgba(245,158,11,.1);  color:#f59e0b; border:1px solid rgba(245,158,11,.22); }
.dv-prio.normale  { background:rgba(59,130,246,.1);  color:#3b82f6; border:1px solid rgba(59,130,246,.22); }
.dv-prio.faible   { background:rgba(34,197,94,.1);   color:#22c55e; border:1px solid rgba(34,197,94,.22); }
.dv-tag { display:inline-flex; align-items:center; gap:.22rem; padding:.14rem .48rem; border-radius:4px; font-size:.63rem; font-weight:700; white-space:nowrap; }
.dv-tag-gray   { background:rgba(100,116,139,.1); border:1px solid rgba(100,116,139,.2); color:#94a3b8; }
.dv-tag-green  { background:rgba(34,197,94,.1);   border:1px solid rgba(34,197,94,.22);  color:#22c55e; }
.dv-tag-red    { background:rgba(239,68,68,.1);   border:1px solid rgba(239,68,68,.22);  color:#ef4444; }
.dv-tag-orange { background:rgba(249,115,22,.1);  border:1px solid rgba(249,115,22,.22); color:#f97316; }
.dv-tag-blue   { background:rgba(59,130,246,.1);  border:1px solid rgba(59,130,246,.22); color:#3b82f6; }
.dv-tag-purple { background:rgba(139,92,246,.1);  border:1px solid rgba(139,92,246,.22); color:#a78bfa; }
.dv-tag-yellow { background:rgba(234,179,8,.1);   border:1px solid rgba(234,179,8,.22);  color:#eab308; }
.dv-card-footer { display:flex; align-items:center; justify-content:space-between; margin-top:.45rem; padding-top:.45rem; border-top:1px solid rgba(255,255,255,.04); font-size:.68rem; color:#4e6a82; }
.dv-card-footer-loc { display:flex; align-items:center; gap:.28rem; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.dv-card-footer-who { display:flex; align-items:center; gap:.28rem; }
.dv-chk { display:flex; align-items:center; gap:.28rem; font-size:.67rem; color:#4e6a82; }
.dv-chk-bar { flex:1; height:3px; background:rgba(255,255,255,.06); border-radius:2px; overflow:hidden; }
.dv-chk-fill { height:100%; border-radius:2px; background:var(--dc); transition:width .3s; }

/* Empty col */
.dv-empty { text-align:center; padding:2rem .5rem; color:#2a4361; font-size:.78rem; }
.dv-empty i { font-size:1.4rem; opacity:.3; display:block; margin-bottom:.5rem; }

/* Detail modal */
.dv-detail-field { display:grid; grid-template-columns:120px 1fr; gap:.4rem; padding:.45rem 0; border-bottom:1px solid rgba(<?php echo "$aR,$aG,$aB";?>,.07); font-size:.82rem; }
.dv-detail-field:last-child { border-bottom:none; }
.dv-detail-lbl { color:#4e6a82; font-size:.73rem; }
</style>

<div class="dv-outer" id="dvOuter">

    <!-- Topbar dispatch -->
    <div class="dv-topbar">
        <div class="dv-title">
            <div class="dv-live-dot" id="liveDot"></div>
            <?php echo htmlspecialchars($DISPATCH_THEME['board_title']??'DISPATCH BOARD'); ?>
        </div>
        <div class="dv-readonly-badge"><i class="fas fa-eye" style="font-size:.65rem"></i>Vue DOJ — Lecture seule</div>
        <div class="dv-refresh-info">
            <i class="fas fa-rotate" style="font-size:.65rem"></i>
            Auto-actualisation <span id="refreshCountdown">30s</span>
        </div>
        <div class="dv-clock" id="dvClock"></div>
    </div>

    <!-- Board -->
    <div class="dv-scroll" id="dvBoard">
    <?php
    $CODE_TEN_DATA   = $CODE_TEN;
    $OTHER_TAGS_DATA = $OTHER_TAGS;

    function dvTagClass($color) {
        $map=['gray'=>'dv-tag-gray','green'=>'dv-tag-green','red'=>'dv-tag-red','orange'=>'dv-tag-orange','blue'=>'dv-tag-blue','purple'=>'dv-tag-purple','yellow'=>'dv-tag-yellow'];
        return $map[$color]??'dv-tag-gray';
    }

    foreach ($lists as $list):
        $cards = $list['cards'];
        $colColor = $list['color']??'#00e5ff';
    ?>
    <div class="dv-col" id="col_<?php echo $list['id'];?>">
        <div class="dv-col-hd">
            <div class="dv-col-color" style="background:<?php echo htmlspecialchars($colColor);?>"></div>
            <div class="dv-col-name"><?php echo htmlspecialchars($list['name']);?></div>
            <div class="dv-col-count"><?php echo count($cards);?></div>
        </div>
        <div class="dv-cards" id="cards_<?php echo $list['id'];?>">
        <?php if(empty($cards)):?>
            <div class="dv-empty"><i class="fas fa-inbox"></i>Aucune mission</div>
        <?php else: foreach($cards as $card):
            $prio = $card['priority']??'normale';
            $prioLabels = ['critique'=>'CRITIQUE','haute'=>'HAUTE','normale'=>'NORMALE','faible'=>'FAIBLE'];
        ?>
        <div class="dv-card" onclick="openDvDetail(<?php echo htmlspecialchars(json_encode($card),ENT_QUOTES);?>)">
            <?php if($card['cover_url']):?><img src="<?php echo htmlspecialchars($card['cover_url']);?>" class="dv-card-cover" loading="lazy"><?php endif;?>
            <div class="dv-card-meta">
                <span class="dv-prio <?php echo $prio;?>"><i class="fas fa-circle" style="font-size:.45rem"></i><?php echo $prioLabels[$prio]??strtoupper($prio);?></span>
                <?php foreach($card['labels'] as $lbl):?>
                <span class="dv-label" style="background:<?php echo htmlspecialchars($lbl['color']);?>20;border:1px solid <?php echo htmlspecialchars($lbl['color']);?>40;color:<?php echo htmlspecialchars($lbl['color']);?>"><?php echo htmlspecialchars($lbl['name']);?></span>
                <?php endforeach;?>
            </div>
            <div class="dv-card-title"><?php echo htmlspecialchars($card['title']);?></div>

            <?php if(!empty($card['grade_tags'])||!empty($card['code_ten_tags'])||!empty($card['other_tags'])):?>
            <div style="display:flex;flex-wrap:wrap;gap:.28rem;margin-bottom:.4rem">
                <?php foreach($card['grade_tags'] as $g):?><span class="dv-tag dv-tag-blue"><i class="fas fa-star" style="font-size:.5rem"></i><?php echo htmlspecialchars($g);?></span><?php endforeach;?>
                <?php foreach($card['code_ten_tags'] as $ct): $ctColor=$CODE_TEN_DATA[$ct]??'gray';?><span class="dv-tag <?php echo dvTagClass($ctColor);?>"><?php echo htmlspecialchars($ct);?></span><?php endforeach;?>
                <?php foreach($card['other_tags'] as $ot): $otColor=$OTHER_TAGS_DATA[$ot]??'gray';?><span class="dv-tag <?php echo dvTagClass($otColor);?>"><?php echo htmlspecialchars($ot);?></span><?php endforeach;?>
            </div>
            <?php endif;?>

            <div class="dv-card-footer">
                <div class="dv-card-footer-loc">
                    <?php if($card['location']??''):?><i class="fas fa-location-dot"></i><?php echo htmlspecialchars($card['location']);?><?php else:?>—<?php endif;?>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem">
                    <?php if(($card['total_items']??0)>0):?>
                    <div class="dv-chk">
                        <i class="fas fa-check-square" style="font-size:.6rem"></i><?php echo $card['done_items'];?>/<?php echo $card['total_items'];?>
                    </div>
                    <?php endif;?>
                    <?php if($card['assigned_to']??''):?><div class="dv-card-footer-who"><i class="fas fa-user" style="font-size:.6rem"></i><?php echo htmlspecialchars(mb_substr($card['assigned_to'],0,16));?></div><?php endif;?>
                </div>
            </div>
        </div>
        <?php endforeach; endif;?>
        </div>
    </div>
    <?php endforeach;?>

    <?php if(empty($lists)):?>
    <div style="display:flex;align-items:center;justify-content:center;flex:1;color:#4e6a82;flex-direction:column;gap:.75rem">
        <i class="fas fa-satellite-dish" style="font-size:2.5rem;opacity:.2"></i>
        <div style="font-family:'Orbitron',monospace;font-size:.85rem;letter-spacing:2px">AUCUNE UNITÉ EN SERVICE</div>
    </div>
    <?php endif;?>
    </div><!-- /dv-scroll -->
</div><!-- /dv-outer -->

<!-- ══ MODAL DÉTAIL CARTE ══════════════════════════════════════════════════ -->
<div id="dvDetailModal" class="modal-overlay hidden" style="z-index:10000">
<div class="modal">
<div class="modal-header" style="background:rgba(0,0,0,.3)">
    <h2 id="dvDetailTitle" style="font-size:.9rem"><i class="fas fa-map-pin" style="color:var(--dc)"></i> Détail de la mission</h2>
    <button class="modal-close" onclick="DOJ.closeModal('dvDetailModal')">×</button>
</div>
<div class="modal-body" id="dvDetailBody" style="padding:1.5rem"></div>
<div class="modal-footer">
    <div style="font-size:.73rem;color:#4e6a82;margin-right:auto"><i class="fas fa-lock" style="margin-right:.3rem"></i>Consultation DOJ uniquement</div>
    <button class="btn btn-secondary" onclick="DOJ.closeModal('dvDetailModal')">Fermer</button>
</div>
</div>
</div>

<script>
const CODE_TEN_MAP   = <?php echo json_encode($CODE_TEN);?>;
const OTHER_TAGS_MAP = <?php echo json_encode($OTHER_TAGS);?>;
const POLL_ENDPOINT  = '<?php echo SITE_URL;?>/pages/doj/dispatch-view.php?ajax=1&action=poll_readonly';
const POLL_INTERVAL  = 30000;
let pollTimer = null, countdown = 30;

// ── Horloge ──────────────────────────────────────────────────────────────
function updateClock(){
    const n=new Date();
    const p=v=>String(v).padStart(2,'0');
    document.getElementById('dvClock').textContent=p(n.getHours())+':'+p(n.getMinutes())+':'+p(n.getSeconds());
}
setInterval(updateClock,1000); updateClock();

// ── Countdown ────────────────────────────────────────────────────────────
function tickCountdown(){
    countdown--;
    if(countdown<=0) countdown=30;
    document.getElementById('refreshCountdown').textContent=countdown+'s';
}
setInterval(tickCountdown,1000);

// ── Polling read-only ─────────────────────────────────────────────────────
async function pollBoard(){
    const dot=document.getElementById('liveDot');
    if(dot){dot.style.boxShadow='0 0 14px #00ff88';dot.style.background='#22c55e';}
    try {
        const r=await fetch(POLL_ENDPOINT,{cache:'no-store'});
        const data=await r.json();
        if(data.ok&&data.lists) renderBoard(data.lists);
        countdown=30;
    } catch(e){ if(dot){dot.style.boxShadow='0 0 8px #ef4444';dot.style.background='#ef4444';} }
    setTimeout(()=>{if(dot){dot.style.boxShadow='0 0 8px var(--dc)';dot.style.background='var(--dc)';}},600);
}
pollTimer=setInterval(pollBoard, POLL_INTERVAL);

function tagClass(color){ const m={'gray':'dv-tag-gray','green':'dv-tag-green','red':'dv-tag-red','orange':'dv-tag-orange','blue':'dv-tag-blue','purple':'dv-tag-purple','yellow':'dv-tag-yellow'};return m[color]||'dv-tag-gray'; }
const prioLabels={'critique':'CRITIQUE','haute':'HAUTE','normale':'NORMALE','faible':'FAIBLE'};
const prioClass={'critique':'critique','haute':'haute','normale':'normale','faible':'faible'};

function renderBoard(lists){
    // Met à jour les cartes sans reconstruire toute la page (évite le clignotement)
    lists.forEach(list=>{
        const cont=document.getElementById('cards_'+list.id);
        if(!cont) return;
        const cards=list.cards||[];
        // Mise à jour simple : reconstruit les cartes
        if(cards.length===0){
            cont.innerHTML='<div class="dv-empty"><i class="fas fa-inbox"></i>Aucune mission</div>';
        } else {
            cont.innerHTML=cards.map(c=>buildCardHTML(c)).join('');
        }
        // Update count
        const countEl=cont.previousElementSibling?.querySelector('.dv-col-count');
        if(countEl) countEl.textContent=cards.length;
    });
}

function buildCardHTML(c){
    const prio=c.priority||'normale';
    const grades=(c.grade_tags||[]).map(g=>`<span class="dv-tag dv-tag-blue"><i class="fas fa-star" style="font-size:.5rem"></i>${esc(g)}</span>`).join('');
    const cTens=(c.code_ten_tags||[]).map(ct=>`<span class="dv-tag ${tagClass(CODE_TEN_MAP[ct]||'gray')}">${esc(ct)}</span>`).join('');
    const oTags=(c.other_tags||[]).map(ot=>`<span class="dv-tag ${tagClass(OTHER_TAGS_MAP[ot]||'gray')}">${esc(ot)}</span>`).join('');
    const labels=(c.labels||[]).map(l=>`<span class="dv-label" style="background:${l.color}20;border:1px solid ${l.color}40;color:${l.color}">${esc(l.name)}</span>`).join('');
    const chk=c.total_items>0?`<div class="dv-chk"><i class="fas fa-check-square" style="font-size:.6rem"></i>${c.done_items}/${c.total_items}</div>`:'';
    const loc=c.location?`<i class="fas fa-location-dot"></i>${esc(c.location)}`:'—';
    const assigned=c.assigned_to?`<div class="dv-card-footer-who"><i class="fas fa-user" style="font-size:.6rem"></i>${esc(c.assigned_to.substring(0,16))}</div>`:'';
    const tags=(grades||cTens||oTags)?`<div style="display:flex;flex-wrap:wrap;gap:.28rem;margin-bottom:.4rem">${grades}${cTens}${oTags}</div>`:'';
    const cover=c.cover_url?`<img src="${c.cover_url}" class="dv-card-cover" loading="lazy">`:'';
    const dataAttr=`data-card='${JSON.stringify(c).replace(/'/g,"&#39;")}'`;
    return `<div class="dv-card" ${dataAttr} onclick="openDvDetailFromEl(this)">
        ${cover}
        <div class="dv-card-meta"><span class="dv-prio ${prioClass[prio]||'normale'}"><i class="fas fa-circle" style="font-size:.45rem"></i>${prioLabels[prio]||prio.toUpperCase()}</span>${labels}</div>
        <div class="dv-card-title">${esc(c.title)}</div>
        ${tags}
        <div class="dv-card-footer"><div class="dv-card-footer-loc">${loc}</div><div style="display:flex;align-items:center;gap:.5rem">${chk}${assigned}</div></div>
    </div>`;
}

function esc(s){ const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }
function openDvDetailFromEl(el){ openDvDetail(JSON.parse(el.dataset.card)); }

function openDvDetail(c){
    document.getElementById('dvDetailTitle').innerHTML=`<i class="fas fa-map-pin" style="color:var(--dc)"></i> ${esc(c.title)}`;
    const prioColors={'critique':'#ef4444','haute':'#f59e0b','normale':'#3b82f6','faible':'#22c55e'};
    const prioNames={'critique':'Critique','haute':'Haute','normale':'Normale','faible':'Faible'};
    const pc=prioColors[c.priority||'normale']||'#3b82f6';
    const pn=prioNames[c.priority||'normale']||'—';
    const grades=(c.grade_tags||[]).map(g=>`<span class="dv-tag dv-tag-blue">${esc(g)}</span>`).join('');
    const cTens =(c.code_ten_tags||[]).map(ct=>`<span class="dv-tag ${tagClass(CODE_TEN_MAP[ct]||'gray')}">${esc(ct)}</span>`).join('');
    const oTags =(c.other_tags||[]).map(ot=>`<span class="dv-tag ${tagClass(OTHER_TAGS_MAP[ot]||'gray')}">${esc(ot)}</span>`).join('');
    const labels=(c.labels||[]).map(l=>`<span class="dv-label" style="background:${l.color}20;border:1px solid ${l.color}40;color:${l.color};padding:.25rem .65rem;border-radius:6px;font-size:.73rem;font-weight:700">${esc(l.name)}</span>`).join('');

    document.getElementById('dvDetailBody').innerHTML=`
        <div style="display:flex;flex-wrap:wrap;gap:.45rem;margin-bottom:1.1rem">
            <span style="padding:.28rem .75rem;background:${pc}18;border:1px solid ${pc}35;border-radius:6px;font-size:.72rem;font-weight:700;color:${pc}"><i class="fas fa-circle" style="font-size:.5rem;margin-right:.3rem"></i>${pn}</span>
            ${labels}
        </div>
        ${c.cover_url?`<img src="${c.cover_url}" style="width:100%;height:160px;object-fit:cover;border-radius:9px;margin-bottom:1rem">`:''}
        <div style="background:rgba(15,23,42,.6);border:1px solid var(--dc-bd);border-radius:10px;padding:1rem;margin-bottom:1rem">
            ${[['Localisation',c.location||'—'],['Assigné à',c.assigned_to||'—'],['Véhicule patrol',c.patrol_vehicle||'—'],['Priorité',pn]].map(([l,v])=>`<div class="dv-detail-field"><span class="dv-detail-lbl">${l}</span><span style="color:#e2eef8;font-weight:500">${esc(v)}</span></div>`).join('')}
        </div>
        ${(grades||cTens||oTags)?`<div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--dc);margin-bottom:.6rem"><i class="fas fa-tags" style="margin-right:.35rem"></i>Tags</div><div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:1rem">${grades}${cTens}${oTags}</div>`:''}
        ${c.description?`<div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--dc);margin-bottom:.6rem"><i class="fas fa-file-lines" style="margin-right:.35rem"></i>Description</div><div style="background:rgba(15,23,42,.5);border:1px solid var(--dc-bd);border-radius:9px;padding:.9rem;font-size:.83rem;line-height:1.65;color:#8fafc8;white-space:pre-wrap;margin-bottom:1rem">${esc(c.description)}</div>`:''}
        <div style="font-size:.7rem;color:#4e6a82;margin-top:.5rem;font-style:italic">Créé le ${new Date(c.created_at||Date.now()).toLocaleDateString('fr-FR')}</div>
    `;
    DOJ.openModal('dvDetailModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>