<?php
/**
 * LSPD — Logs Dispatch : archive des cartes supprimées + historique + audit.
 * pages/lspd/logs_dispatch.php
 *
 * Permissions (faction_acl) :
 *   - dispatch-logs.view   (LOGS_DISPATCH)        → accès à la page
 *   - dispatch-logs.delete (DELETE_LOGS_DISPATCH) → purge des logs par période
 */
require_once __DIR__ . '/../../config/config.php';
requireLogin();
require_once __DIR__ . '/../../includes/faction_acl.php';
require_once __DIR__ . '/lspd_log.php';

$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);

$canView   = faction_acl_can($db, $uid, 'LSPD', 'dispatch-logs.view');
$canDelete = faction_acl_can($db, $uid, 'LSPD', 'dispatch-logs.delete');
if (!$canView) { redirect(SITE_URL . '/pages/lspd/dashboard.php'); }

$DISPATCH_URL = SITE_URL . '/public/uploads/dispatch/';
function dcover($c, $base) { if (!$c) return ''; return preg_match('#^https?://#i', $c) ? $c : $base . $c; }

$CONFIRM_PHRASE = 'Je confirme vouloir supprimer définitivement ces logs';
$msg = ''; $msgType = '';

// ── Purge des logs par période ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge_logs') {
    if (!$canDelete) { $msg = 'Permission requise.'; $msgType = 'error'; }
    elseif (trim($_POST['confirm_phrase'] ?? '') !== $CONFIRM_PHRASE) { $msg = 'Phrase de confirmation incorrecte.'; $msgType = 'error'; }
    else {
        $period = $_POST['period'] ?? '30d';
        $periods = ['24h'=>'-24 hours','7d'=>'-7 days','30d'=>'-30 days','90d'=>'-90 days','6m'=>'-6 months','1y'=>'-1 year'];
        $labels  = ['24h'=>'Dernières 24h','7d'=>'7 derniers jours','30d'=>'30 derniers jours','90d'=>'90 derniers jours','6m'=>'6 derniers mois','1y'=>'12 derniers mois','custom'=>'Période personnalisée'];
        try {
            if ($period === 'custom') {
                $from = $_POST['from'] ?? ''; $to = $_POST['to'] ?? '';
                $from = $from ? date('Y-m-d 00:00:00', strtotime($from)) : '2000-01-01';
                $to   = $to   ? date('Y-m-d 23:59:59', strtotime($to))   : date('Y-m-d 23:59:59');
                $cl = $db->prepare("DELETE FROM dispatch_card_logs WHERE created_at BETWEEN ? AND ?"); $cl->execute([$from,$to]); $n1=$cl->rowCount();
                $ca = $db->prepare("DELETE FROM dispatch_cards_archive WHERE deleted_at BETWEEN ? AND ?"); $ca->execute([$from,$to]); $n2=$ca->rowCount();
                $periodLabel = 'Personnalisée ('.$from.' → '.$to.')';
            } else {
                $cut = date('Y-m-d H:i:s', strtotime($periods[$period] ?? '-30 days'));
                $cl = $db->prepare("DELETE FROM dispatch_card_logs WHERE created_at >= ?"); $cl->execute([$cut]); $n1=$cl->rowCount();
                $ca = $db->prepare("DELETE FROM dispatch_cards_archive WHERE deleted_at >= ?"); $ca->execute([$cut]); $n2=$ca->rowCount();
                $periodLabel = $labels[$period] ?? $period;
            }
            $total = ($n1??0) + ($n2??0);
            // Audit dans les logs généraux LSPD
            lspd_log($db, $uid, 'purge_dispatch_logs', 'logs_dispatch', null, $periodLabel, 'Logs supprimés: '.($n1??0).' · Cartes archivées supprimées: '.($n2??0));
            $msg = "Purge effectuée — $total élément(s) supprimé(s) ($periodLabel). Action enregistrée dans les logs LSPD."; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
}

// ── Données : cartes archivées + historique groupé ───────────────────────────
$cards = [];
try {
    $cards = $db->query("SELECT a.*, u.username AS del_user, p.firstname AS del_fn, p.lastname AS del_ln
                         FROM dispatch_cards_archive a
                         LEFT JOIN users u ON u.id=a.deleted_by LEFT JOIN profiles p ON p.id=u.profile_id
                         ORDER BY a.deleted_at DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cards = []; }

$logsByCard = [];
if ($cards) {
    $ids = array_map(fn($c)=>(int)$c['id'], $cards);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $ls = $db->prepare("SELECT lg.card_id,lg.action,lg.detail,lg.created_at,u.username,p.firstname,p.lastname,p.avatar
                            FROM dispatch_card_logs lg LEFT JOIN users u ON u.id=lg.user_id LEFT JOIN profiles p ON p.id=u.profile_id
                            WHERE lg.card_id IN ($ph) ORDER BY lg.created_at ASC");
        $ls->execute($ids);
        foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) { $logsByCard[(int)$l['card_id']][] = $l; }
    } catch (Exception $e) {}
}

$LOG_ICON = ['create'=>'fa-plus','delete'=>'fa-trash','agents'=>'fa-user','code_ten'=>'fa-tower-broadcast','tags'=>'fa-tag','status'=>'fa-flag','cover_add'=>'fa-image','cover_remove'=>'fa-image','update_title'=>'fa-pen','update_description'=>'fa-align-left','update_location'=>'fa-location-dot','update_patrol_vehicle'=>'fa-car','update_vehicle_plate'=>'fa-id-card'];

$pageTitle = 'Logs Dispatch';
include __DIR__ . '/includes/header.php';
?>
<style>
.ld-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:14px;overflow:hidden;margin-bottom:1rem}
.ld-head{display:flex;gap:1rem;padding:1rem 1.2rem;align-items:flex-start}
.ld-cover{width:120px;height:80px;border-radius:10px;background:#020508 center/cover no-repeat;flex-shrink:0;display:grid;place-items:center;border:1px solid var(--bdr)}
.ld-cover i{color:var(--txt-m);opacity:.4}
.ld-badges{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.4rem}
.ld-meta{display:flex;gap:1.2rem;flex-wrap:wrap;font-size:.72rem;color:var(--txt-m);margin-top:.5rem}
.ld-timeline{border-top:1px solid var(--bdr);padding:1rem 1.2rem;background:rgba(0,0,0,.18);display:none}
.ld-timeline.open{display:block}
.ld-log{display:flex;gap:.65rem;align-items:flex-start;padding:.4rem 0;position:relative}
.ld-log-av{width:30px;height:30px;border-radius:50%;flex-shrink:0;object-fit:cover;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:grid;place-items:center;color:#fff;font-weight:700;font-size:.72rem;overflow:hidden}
.ld-log-av img{width:100%;height:100%;object-fit:cover}
.ld-log-line{flex:1;font-size:.82rem}
.ld-log-time{font-size:.66rem;color:var(--txt-m);font-family:'IBM Plex Mono',monospace}
.ld-empty{text-align:center;padding:3.5rem;color:var(--txt-m)}
.ld-empty i{font-size:2.4rem;opacity:.18;display:block;margin-bottom:.7rem}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.3rem;flex-wrap:wrap;gap:.7rem">
  <div>
    <h1 style="font-family:'Montserrat',sans-serif;font-size:1.35rem;font-weight:800;color:#fff;margin:0 0 .25rem;display:flex;align-items:center;gap:.5rem"><i class="fas fa-clock-rotate-left" style="color:var(--blue-l)"></i> Logs Dispatch — Cartes archivées</h1>
    <div style="font-size:.76rem;color:var(--txt-s)"><?php echo count($cards);?> carte(s) supprimée(s) · historique complet conservé</div>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:.5rem;padding:.5rem .8rem;background:#020508;border:1px solid rgba(37,99,235,.2);border-radius:8px;min-width:280px">
      <i class="fas fa-search" style="color:var(--txt-s)"></i>
      <input type="text" id="ldSearch" placeholder="Titre, agent, matricule, code ten, statut..." style="flex:1;background:none;border:none;outline:none;color:var(--txt);font-family:inherit;font-size:.84rem" oninput="ldFilter()">
    </div>
    <?php if($canDelete):?><button class="btn btn-danger btn-sm" onclick="LSPD.openModal('purgeLogsModal')"><i class="fas fa-trash-can"></i> Supprimer des logs</button><?php endif;?>
  </div>
</div>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>" style="margin-bottom:1rem"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo htmlspecialchars($msg);?></div><?php endif;?>

<div id="ldList">
<?php if(empty($cards)):?>
  <div class="ld-empty"><i class="fas fa-clock-rotate-left"></i>Aucune carte archivée. Les cartes supprimées dans le Dispatch apparaîtront ici.</div>
<?php else: foreach($cards as $c):
    $cover=dcover($c['cover_image']??'', $DISPATCH_URL);
    $delName=mdt_rp_display_name($c['del_fn']??null,$c['del_ln']??null,(string)($c['del_user']??'—'));
    $codes=array_filter(array_map('trim',explode(',',$c['code_ten_tags']??'')));
    $stats=array_filter(array_map('trim',explode(',',$c['other_tags']??'')));
    $cardLogs=$logsByCard[(int)$c['id']]??[];
    $searchBlob=strtolower(($c['title']??'').' '.($c['description']??'').' '.($c['assigned_to']??'').' '.($c['code_ten_tags']??'').' '.($c['other_tags']??'').' '.$delName);
?>
  <div class="ld-card" data-search="<?php echo htmlspecialchars($searchBlob,ENT_QUOTES);?>">
    <div class="ld-head">
      <div class="ld-cover" <?php echo $cover?'style="background-image:url(\''.htmlspecialchars($cover).'\')"':'';?>><?php echo $cover?'':'<i class="fas fa-image"></i>';?></div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
          <span style="font-weight:800;font-size:1rem;color:#fff"><?php echo htmlspecialchars($c['title']?:'Carte #'.$c['id']);?></span>
          <?php if(!empty($c['priority'])):?><span class="badge bg-blue" style="font-size:.6rem"><?php echo htmlspecialchars($c['priority']);?></span><?php endif;?>
        </div>
        <?php if(!empty($c['description'])):?><div style="font-size:.8rem;color:var(--txt-s);margin-top:.3rem;line-height:1.5"><?php echo nl2br(htmlspecialchars(mb_substr($c['description'],0,300)));?></div><?php endif;?>
        <div class="ld-badges">
          <?php foreach($codes as $cd):?><span class="badge bg-purple" style="font-size:.6rem"><i class="fas fa-tower-broadcast" style="font-size:.55rem"></i> <?php echo htmlspecialchars($cd);?></span><?php endforeach;?>
          <?php foreach($stats as $st):?><span class="badge bg-yellow" style="font-size:.6rem"><?php echo htmlspecialchars($st);?></span><?php endforeach;?>
        </div>
        <?php if(!empty($c['assigned_to'])):?><div style="font-size:.74rem;color:var(--txt-s);margin-top:.4rem"><i class="fas fa-users" style="opacity:.5;margin-right:.3rem"></i><?php echo htmlspecialchars($c['assigned_to']);?></div><?php endif;?>
        <div class="ld-meta">
          <span><i class="fas fa-folder" style="opacity:.5"></i> <?php echo htmlspecialchars($c['list_name']??'—');?></span>
          <span><i class="fas fa-calendar-plus" style="opacity:.5"></i> Créée <?php echo !empty($c['created_at'])?date('d/m/Y',strtotime($c['created_at'])):'—';?></span>
          <span style="color:#fca5a5"><i class="fas fa-trash" style="opacity:.6"></i> Supprimée le <?php echo !empty($c['deleted_at'])?date('d/m/Y à H:i',strtotime($c['deleted_at'])):'—';?> par <strong><?php echo htmlspecialchars($delName);?></strong></span>
        </div>
      </div>
      <button class="btn btn-ghost btn-sm" onclick="this.closest('.ld-card').querySelector('.ld-timeline').classList.toggle('open')"><i class="fas fa-clock-rotate-left"></i> Historique (<?php echo count($cardLogs);?>)</button>
    </div>
    <div class="ld-timeline">
      <?php if(empty($cardLogs)):?><div style="color:var(--txt-m);font-size:.8rem">Aucun log enregistré pour cette carte.</div>
      <?php else: foreach(array_reverse($cardLogs) as $l):
          $ln=mdt_rp_display_name($l['firstname']??null,$l['lastname']??null,(string)($l['username']??'—'));
          $av=$l['avatar']??''; $avSrc=$av?(preg_match('#^https?://#',$av)?$av:SITE_URL.$av):'';
          $ic=$LOG_ICON[$l['action']]??'fa-circle-dot';
      ?>
      <div class="ld-log">
        <div class="ld-log-av"><?php echo $avSrc?'<img src="'.htmlspecialchars($avSrc).'">':htmlspecialchars(mb_strtoupper(mb_substr($ln,0,1)));?></div>
        <div class="ld-log-line"><i class="fas <?php echo $ic;?>" style="color:var(--blue-l);margin-right:.35rem;font-size:.72rem"></i><strong><?php echo htmlspecialchars($ln);?></strong> <?php echo htmlspecialchars($l['detail']?:$l['action']);?>
          <div class="ld-log-time"><?php echo date('d/m/Y',strtotime($l['created_at']));?> à <?php echo date('H:i',strtotime($l['created_at']));?></div>
        </div>
      </div>
      <?php endforeach;endif;?>
    </div>
  </div>
<?php endforeach;endif;?>
</div>
<div id="ldNoRes" class="ld-empty" style="display:none"><i class="fas fa-magnifying-glass"></i>Aucun résultat.</div>

<?php if($canDelete):?>
<!-- ══ MODAL PURGE ══ -->
<div id="purgeLogsModal" class="modal-ov hidden">
<div class="modal modal-sm">
  <div class="modal-hd" style="background:rgba(239,68,68,.08);border-bottom:1px solid rgba(239,68,68,.25)"><h2 style="color:#f87171"><i class="fas fa-trash-can" style="color:#ef4444"></i> Supprimer des logs Dispatch</h2><button class="modal-cl" onclick="LSPD.closeModal('purgeLogsModal')">×</button></div>
  <form method="POST"><div class="modal-bd">
    <input type="hidden" name="action" value="purge_logs">
    <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:.85rem 1rem;margin-bottom:1rem;font-size:.82rem;color:#fca5a5"><i class="fas fa-triangle-exclamation" style="margin-right:.4rem"></i>Action <strong>irréversible</strong>. Une entrée d'audit sera créée dans les logs LSPD.</div>
    <div class="form-group" style="margin-bottom:.9rem">
      <label class="form-label">Période à supprimer</label>
      <select name="period" id="purgePeriod" class="form-select" onchange="document.getElementById('purgeCustom').style.display=this.value==='custom'?'grid':'none'">
        <option value="24h">Dernières 24h</option>
        <option value="7d">7 derniers jours</option>
        <option value="30d" selected>30 derniers jours</option>
        <option value="90d">90 derniers jours</option>
        <option value="6m">6 derniers mois</option>
        <option value="1y">1 an</option>
        <option value="custom">Période personnalisée</option>
      </select>
    </div>
    <div id="purgeCustom" style="display:none;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.9rem">
      <div class="form-group"><label class="form-label">Du</label><input type="date" name="from" class="form-input"></div>
      <div class="form-group"><label class="form-label">Au</label><input type="date" name="to" class="form-input"></div>
    </div>
    <div class="form-group">
      <label class="form-label">Recopiez exactement : <strong style="color:#f87171"><?php echo htmlspecialchars($CONFIRM_PHRASE);?></strong></label>
      <input type="text" name="confirm_phrase" id="purgePhrase" class="form-input" autocomplete="off" placeholder="<?php echo htmlspecialchars($CONFIRM_PHRASE);?>" oninput="document.getElementById('purgeBtn').disabled=(this.value!==<?php echo json_encode($CONFIRM_PHRASE);?>)">
    </div>
  </div>
  <div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('purgeLogsModal')">Annuler</button><button type="submit" id="purgeBtn" class="btn btn-danger" disabled><i class="fas fa-trash-can"></i> Supprimer définitivement</button></div>
  </form>
</div></div>
<?php endif;?>

<script>
function ldFilter(){
  const q=(document.getElementById('ldSearch').value||'').toLowerCase().trim();
  let any=false;
  document.querySelectorAll('#ldList .ld-card').forEach(c=>{ const m=!q||(c.dataset.search||'').includes(q); c.style.display=m?'':'none'; if(m)any=true; });
  document.getElementById('ldNoRes').style.display=any?'none':'block';
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>
