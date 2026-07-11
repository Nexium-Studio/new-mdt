<?php
/**
 * LSPD — Temps de service & Paie
 * pages/lspd/temps_service.php
 *
 * Accessible uniquement avec la permission faction_acl `temps-service.manage`.
 * Réutilise company_shifts (heures), lspd_service_totals (saison) et
 * lspd_ranks.salary_hourly (salaire $/h, cf. divisions.php).
 */
require_once __DIR__ . '/../../config/config.php';
requireLogin();
require_once __DIR__ . '/includes/service_api.php';
require_once __DIR__ . '/../../includes/faction_acl.php';
require_once __DIR__ . '/../../includes/matricule_helper.php';

$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);

$canManage = faction_acl_can($db, $uid, 'LSPD', 'temps-service.manage');

lspd_service_totals_ensure($db);
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `lspd_payments` (
        `id`         INT(11) NOT NULL AUTO_INCREMENT,
        `officer_id` INT(11) NOT NULL,
        `payer_id`   INT(11) NULL,
        `minutes`    INT(11) NOT NULL DEFAULT 0,
        `amount`     DECIMAL(10,2) NOT NULL DEFAULT 0,
        `paid_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_officer` (`officer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

/** Notifie l'agent payé via le système mail interne. Best-effort. */
function lspd_payroll_notify(PDO $db, int $payerUid, int $receiverUid, array $info): bool
{
    try {
        $r = $db->prepare("SELECT id,address FROM mail_accounts WHERE user_id=? AND is_active=1 ORDER BY id LIMIT 1");
        $r->execute([$receiverUid]); $recv = $r->fetch(PDO::FETCH_ASSOC);
        if (!$recv) return false;
        $s = $db->prepare("SELECT id,address,display_name FROM mail_accounts WHERE user_id=? AND is_active=1 ORDER BY id LIMIT 1");
        $s->execute([$payerUid]); $send = $s->fetch(PDO::FETCH_ASSOC);
        if (!$send) return false; // pas de compte expéditeur → on s'appuie sur le registre lspd_payments

        $logo = htmlspecialchars($info['logo'] ?? '', ENT_QUOTES);
        $logoHtml = $logo ? '<img src="'.$logo.'" alt="LSPD" style="height:48px;margin-bottom:10px">' : '';
        $body = '<div style="font-family:Arial,sans-serif;color:#0f172a">'
            . $logoHtml
            . '<h2 style="color:#1d4ed8;margin:0 0 8px">Los Santos Police Department — Bulletin de paie</h2>'
            . '<p>Bonjour,</p>'
            . '<p>Un versement vient d\'être effectué pour votre temps de service.</p>'
            . '<table style="border-collapse:collapse;margin:12px 0">'
            . '<tr><td style="padding:4px 12px 4px 0;color:#475569">Agent payeur</td><td style="font-weight:700">'.htmlspecialchars($info['payer_name']).'</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#475569">Agent bénéficiaire</td><td style="font-weight:700">'.htmlspecialchars($info['receiver_name']).'</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#475569">Heures réglées</td><td style="font-weight:700">'.htmlspecialchars($info['hours']).'</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#475569">Montant total</td><td style="font-weight:700;color:#16a34a">$'.number_format($info['amount'],2,'.',' ').'</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#475569">Date</td><td>'.htmlspecialchars($info['date']).'</td></tr>'
            . '</table>'
            . '<p style="color:#475569;font-size:13px">Message officiel automatique — Service de la paie LSPD.</p></div>';

        $subject = 'Paie LSPD — versement de $'.number_format($info['amount'],2,'.',' ');
        $db->prepare("INSERT INTO mail_threads (subject) VALUES (?)")->execute([$subject]);
        $threadId = $db->lastInsertId();
        $db->prepare("
            INSERT INTO mail_messages
            (thread_id, from_account_id, from_address, from_name, to_addresses, cc_addresses, bcc_addresses, subject, body, body_type, reply_to_id, type, sent_at)
            VALUES (?,?,?,?,?,?,?,?,?,'html',NULL,'sent',NOW())
        ")->execute([
            $threadId, $send['id'], $send['address'], $send['display_name'] ?: 'LSPD Paie',
            json_encode([$recv['address']]), json_encode([]), json_encode([]),
            $subject, $body,
        ]);
        $msgId = $db->lastInsertId();
        $db->prepare("INSERT INTO mail_boxes (message_id,account_id,folder,is_read) VALUES (?,?,'sent',1)")->execute([$msgId, $send['id']]);
        $db->prepare("INSERT IGNORE INTO mail_boxes (message_id,account_id,folder) VALUES (?,?,'inbox')")->execute([$msgId, $recv['id']]);
        $db->prepare("INSERT INTO mail_notifications (user_id,account_id,message_id) VALUES (?,?,?)")->execute([$receiverUid, $recv['id'], $msgId]);
        $db->prepare("UPDATE mail_threads SET last_message_at=NOW() WHERE id=?")->execute([$threadId]);
        return true;
    } catch (Throwable $e) { return false; }
}

// ── AJAX actions ──────────────────────────────────────────────────────────────
$__tsAction = $_POST['ts_action'] ?? '';
if ($__tsAction !== '') {
    header('Content-Type: application/json');
    if (!$canManage) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Permission requise']); exit; }
    $officerId = (int)($_POST['officer_id'] ?? 0);
    if ($officerId <= 0) { echo json_encode(['success'=>false,'error'=>'Agent invalide']); exit; }

    if ($__tsAction === 'reset') {
        lspd_service_reset_season($db, $officerId);
        echo json_encode(['success'=>true]); exit;
    }

    if ($__tsAction === 'pay') {
        $season = lspd_service_season($db, $officerId);
        $minutes = (int)$season['season'];
        if ($minutes <= 0) { echo json_encode(['success'=>false,'error'=>'Aucune heure à régler pour cet agent']); exit; }
        // Taux horaire du grade
        $rankStmt = $db->prepare("SELECT faction_rank FROM users WHERE id=?"); $rankStmt->execute([$officerId]);
        $rank = (string)$rankStmt->fetchColumn();
        $rates = lspd_hourly_rate_map($db);
        $rate  = (float)($rates[$rank] ?? 0);
        $amount = round($rate * $minutes / 60, 2);
        // Enregistre le paiement
        $db->prepare("INSERT INTO lspd_payments (officer_id,payer_id,minutes,amount) VALUES (?,?,?,?)")
           ->execute([$officerId, $uid, $minutes, $amount]);
        // Notification mail interne (best-effort)
        $logo=''; try{$lg=$db->prepare("SELECT custom_logo_url FROM admin_panel_config WHERE panel_key='LSPD'");$lg->execute();$logo=(string)$lg->fetchColumn();}catch(Exception $e){}
        $nameOf = function(int $id) use ($db): string {
            try { $s=$db->prepare("SELECT u.username,p.firstname,p.lastname FROM users u LEFT JOIN profiles p ON p.id=u.profile_id WHERE u.id=?"); $s->execute([$id]); $r=$s->fetch(PDO::FETCH_ASSOC);
                  return $r ? mdt_rp_display_name($r['firstname'],$r['lastname'],$r['username']) : '#'.$id; }
            catch(Throwable $e){ return '#'.$id; }
        };
        $h = intdiv($minutes,60).'h '.($minutes%60).'min';
        $mailed = lspd_payroll_notify($db, $uid, $officerId, [
            'logo'=>$logo, 'payer_name'=>$nameOf($uid), 'receiver_name'=>$nameOf($officerId),
            'hours'=>$h, 'amount'=>$amount, 'date'=>date('d/m/Y H:i'),
        ]);
        echo json_encode(['success'=>true,'amount'=>$amount,'minutes'=>$minutes,'mailed'=>$mailed]); exit;
    }

    echo json_encode(['success'=>false,'error'=>'Action inconnue']); exit;
}

$pageTitle = 'Temps de Service';
include __DIR__ . '/includes/header.php';

// ── Données roster ────────────────────────────────────────────────────────────
$rates = lspd_hourly_rate_map($db);
$roster = [];
try {
    $sql = "
      SELECT u.id, u.username, u.faction_rank,
             p.firstname, p.lastname,
             COALESCE(t.season_start,'2000-01-01 00:00:00') AS season_start,
             COALESCE(sh.season_min,0) AS season_min,
             COALESCE(sh.life_min,0)   AS life_min,
             lp.last_paid
      FROM users u
      LEFT JOIN profiles p ON p.id = u.profile_id
      LEFT JOIN lspd_service_totals t ON t.user_id = u.id
      LEFT JOIN (
          SELECT cs.user_id,
                 SUM(CASE WHEN cs.shift_end IS NOT NULL
                          AND cs.shift_start >= COALESCE((SELECT season_start FROM lspd_service_totals WHERE user_id=cs.user_id),'2000-01-01 00:00:00')
                          THEN cs.duration_minutes ELSE 0 END) AS season_min,
                 SUM(CASE WHEN cs.shift_end IS NOT NULL THEN cs.duration_minutes ELSE 0 END) AS life_min
          FROM company_shifts cs
          WHERE cs.faction='LSPD'
          GROUP BY cs.user_id
      ) sh ON sh.user_id = u.id
      LEFT JOIN (SELECT officer_id, MAX(paid_at) last_paid FROM lspd_payments GROUP BY officer_id) lp ON lp.officer_id = u.id
      WHERE u.faction='LSPD' AND u.is_active=1
      ORDER BY u.username
    ";
    $roster = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $roster = []; }

$matricules = lspd_matricules_map($db, array_column($roster, 'id'));

// ── Données camemberts (présence par période) ─────────────────────────────────
$periodRows = [];
try {
    $periodRows = $db->query("
      SELECT cs.user_id, u.username, p.firstname, p.lastname,
        SUM(CASE WHEN YEARWEEK(shift_start,3)=YEARWEEK(CURDATE(),3) THEN duration_minutes ELSE 0 END) wk,
        SUM(CASE WHEN YEAR(shift_start)=YEAR(CURDATE()) AND MONTH(shift_start)=MONTH(CURDATE()) THEN duration_minutes ELSE 0 END) mo,
        SUM(CASE WHEN YEAR(shift_start)=YEAR(CURDATE()) AND QUARTER(shift_start)=QUARTER(CURDATE()) THEN duration_minutes ELSE 0 END) qt,
        SUM(CASE WHEN YEAR(shift_start)=YEAR(CURDATE()) THEN duration_minutes ELSE 0 END) yr
      FROM company_shifts cs
      JOIN users u ON u.id=cs.user_id
      LEFT JOIN profiles p ON p.id=u.profile_id
      WHERE cs.faction='LSPD' AND cs.shift_end IS NOT NULL
      GROUP BY cs.user_id, u.username, p.firstname, p.lastname
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $periodRows = []; }

function ts_top_period(array $rows, string $key): array {
    $items = [];
    foreach ($rows as $r) {
        $m = (int)($r[$key] ?? 0);
        if ($m <= 0) continue;
        $items[] = ['name'=>mdt_rp_display_name($r['firstname'],$r['lastname'],$r['username']), 'min'=>$m];
    }
    usort($items, fn($a,$b)=>$b['min']-$a['min']);
    $top = array_slice($items, 0, 6);
    $rest = array_slice($items, 6);
    if ($rest) { $top[] = ['name'=>'Autres', 'min'=>array_sum(array_column($rest,'min'))]; }
    return $top;
}
$charts = [
    'Semaine'   => ts_top_period($periodRows, 'wk'),
    'Mois'      => ts_top_period($periodRows, 'mo'),
    'Trimestre' => ts_top_period($periodRows, 'qt'),
    'Année'     => ts_top_period($periodRows, 'yr'),
];

$fmtMin = fn($m) => intdiv((int)$m,60).'h '.((int)$m%60).'min';
?>
<style>
.ts-charts{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem}
@media(max-width:1100px){.ts-charts{grid-template-columns:repeat(2,1fr)}}
.ts-chart-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:13px;padding:1rem}
.ts-chart-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);margin-bottom:.7rem;text-align:center}
.ts-chart-card canvas{max-height:160px}
.ts-empty-chart{height:160px;display:grid;place-items:center;color:var(--txt-m);font-size:.78rem}
.ts-status{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .55rem;border-radius:5px;font-size:.65rem;font-weight:700;text-transform:uppercase}
.ts-st-pending{background:rgba(245,158,11,.1);color:#fde68a;border:1px solid rgba(245,158,11,.22)}
.ts-st-paid{background:rgba(16,185,129,.1);color:#6ee7b7;border:1px solid rgba(16,185,129,.22)}
.ts-st-none{background:rgba(107,114,128,.1);color:#9ca3af;border:1px solid rgba(107,114,128,.2)}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.3rem;flex-wrap:wrap;gap:.7rem">
  <div>
    <h1 style="font-family:'Syne',serif;font-size:1.35rem;font-weight:800;color:#fff;margin:0 0 .25rem;display:flex;align-items:center;gap:.5rem"><i class="fas fa-business-time" style="color:var(--blue-l)"></i> Temps de Service & Paie</h1>
    <div style="font-size:.76rem;color:var(--txt-s)">Saison en cours · salaire horaire par grade (divisions) · paie par agent</div>
  </div>
</div>

<!-- Camemberts présence -->
<div class="ts-charts">
  <?php foreach ($charts as $label => $data): ?>
  <div class="ts-chart-card">
    <div class="ts-chart-title">Présence — <?php echo htmlspecialchars($label); ?></div>
    <?php if (empty($data)): ?>
      <div class="ts-empty-chart">Aucune donnée</div>
    <?php else: ?>
      <canvas id="chart<?php echo htmlspecialchars($label); ?>"></canvas>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tableau principal -->
<div class="tbl-wrap"><table class="tbl">
  <thead><tr>
    <th>Agent</th><th>Grade</th><th>Matricule</th><th>Temps saison</th><th>Salaire calculé</th><th>Statut</th><th style="text-align:right">Actions</th>
  </tr></thead>
  <tbody>
  <?php if (empty($roster)): ?>
    <tr><td colspan="7" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucun agent LSPD actif</td></tr>
  <?php else: foreach ($roster as $r):
      $name = mdt_rp_display_name($r['firstname'],$r['lastname'],$r['username']);
      $mat  = $matricules[(int)$r['id']] ?? null;
      $seasonMin = (int)$r['season_min'];
      $rate = (float)($rates[$r['faction_rank']] ?? 0);
      $amount = round($rate * $seasonMin / 60, 2);
      $paidThisSeason = !empty($r['last_paid']) && strtotime($r['last_paid']) >= strtotime($r['season_start']);
      if ($paidThisSeason) { $stCls='ts-st-paid'; $stLbl='Payé'; }
      elseif ($seasonMin>0) { $stCls='ts-st-pending'; $stLbl='En attente'; }
      else { $stCls='ts-st-none'; $stLbl='Aucune heure'; }
  ?>
    <tr data-officer="<?php echo (int)$r['id']; ?>">
      <td style="font-weight:600"><?php echo htmlspecialchars($name); ?></td>
      <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($r['faction_rank'] ?: '—'); ?></td>
      <td class="mono" style="font-size:.75rem;color:var(--blue-xl)"><?php echo $mat?htmlspecialchars($mat):'<span style="color:var(--txt-m)">—</span>'; ?></td>
      <td class="mono" data-cell="time"><?php echo htmlspecialchars($fmtMin($seasonMin)); ?></td>
      <td class="mono" data-cell="amount" style="color:#6ee7b7;font-weight:700">$<?php echo number_format($amount,2,'.',' '); ?></td>
      <td data-cell="status"><span class="ts-status <?php echo $stCls; ?>"><?php echo $stLbl; ?></span></td>
      <td style="text-align:right">
        <?php if ($canManage): ?>
        <div style="display:flex;gap:.3rem;justify-content:flex-end;flex-wrap:nowrap">
          <button class="btn btn-secondary btn-xs" onclick="tsRecalc()" title="Recalculer"><i class="fas fa-calculator"></i></button>
          <button class="btn btn-success btn-xs" onclick="tsPay(<?php echo (int)$r['id']; ?>,'<?php echo htmlspecialchars(addslashes($name)); ?>')"><i class="fas fa-money-bill-wave"></i> Payer</button>
          <button class="btn btn-warning btn-xs" onclick="tsReset(<?php echo (int)$r['id']; ?>,'<?php echo htmlspecialchars(addslashes($name)); ?>')" title="Reset saison"><i class="fas fa-rotate-left"></i></button>
        </div>
        <?php else: ?><span style="color:var(--txt-m);font-size:.72rem">Lecture seule</span><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const TS_CHARTS = <?php echo json_encode($charts); ?>;
const TS_COLORS = ['#2563eb','#06b6d4','#10b981','#f59e0b','#8b5cf6','#ec4899','#64748b'];
function tsFmt(m){return Math.floor(m/60)+'h '+(m%60)+'min';}
document.addEventListener('DOMContentLoaded', function(){
  if(!window.Chart) return;
  Object.keys(TS_CHARTS).forEach(label=>{
    const data = TS_CHARTS[label]; if(!data || !data.length) return;
    const cv = document.getElementById('chart'+label); if(!cv) return;
    new Chart(cv, {
      type:'doughnut',
      data:{ labels:data.map(d=>d.name), datasets:[{ data:data.map(d=>d.min), backgroundColor:TS_COLORS, borderWidth:0 }] },
      options:{ plugins:{ legend:{ position:'bottom', labels:{ color:'#94a3b8', font:{size:10}, boxWidth:10 } },
        tooltip:{ callbacks:{ label:c=>c.label+': '+tsFmt(c.parsed) } } }, cutout:'58%' }
    });
  });
});

function tsRecalc(){ location.reload(); }

function tsPay(id, name){
  LSPD.confirm({
    title:'Confirmer le paiement', icon:'fas fa-money-bill-wave', okLabel:'Payer', okClass:'btn-success',
    color:'rgba(16,185,129,.12)', border:'rgba(16,185,129,.3)', textColor:'#6ee7b7',
    msg:'Régler le temps de service de <strong>'+name+'</strong> pour la saison en cours ?',
    callback: async ()=>{
      const fd=new FormData(); fd.append('ts_action','pay'); fd.append('officer_id',id);
      const r=await fetch('temps_service.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
      const d=await r.json();
      if(d.success){ LSPD.toast('Paiement de $'+d.amount.toFixed(2)+' enregistré'+(d.mailed?' · notification envoyée':''),'success'); setTimeout(()=>location.reload(),900); }
      else LSPD.toast(d.error||'Erreur','error');
    }
  });
}

function tsReset(id, name){
  LSPD.confirm({
    title:'Reset de la saison', icon:'fas fa-rotate-left', okLabel:'Réinitialiser', okClass:'btn-warning',
    color:'rgba(245,158,11,.12)', border:'rgba(245,158,11,.3)', textColor:'#fde68a',
    msg:'Réinitialiser la <strong>saison en cours</strong> de '+name+' ?<br>Le total global (lifetime) est conservé.',
    callback: async ()=>{
      const fd=new FormData(); fd.append('ts_action','reset'); fd.append('officer_id',id);
      const r=await fetch('temps_service.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
      const d=await r.json();
      if(d.success){ LSPD.toast('Saison réinitialisée','success'); setTimeout(()=>location.reload(),700); }
      else LSPD.toast(d.error||'Erreur','error');
    }
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
