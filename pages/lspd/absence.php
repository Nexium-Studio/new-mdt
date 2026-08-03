<?php
/**
 * pages/lspd/absence.php — Gestion des absences des agents LSPD.
 *
 * • Tous les agents peuvent déclarer une absence et consulter leurs propres demandes.
 * • Les détenteurs de la permission faction `absence.manage` (ABSENCE_GESTION,
 *   configurable depuis pages/staff/users/roles.php) valident / refusent les demandes.
 * • Les absences sont liées au PERSONNAGE ACTIF (mdt_get_active_character), pas au compte.
 * • Notifications : bannière in-app + badge sidebar (robuste) ET mail interne best-effort.
 */
require_once __DIR__ . '/../../config/config.php';
requireLogin();
require_once __DIR__ . '/../../includes/characters.php';
require_once __DIR__ . '/../../includes/faction_acl.php';
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');

$pageTitle='Gestion des absences';
$db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0);

// ── Schéma ────────────────────────────────────────────────────────────────────
try{$db->exec("CREATE TABLE IF NOT EXISTS `lspd_absences` (
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `character_id` INT NULL DEFAULT NULL,
    `profile_id` INT NULL DEFAULT NULL,
    `agent_name` VARCHAR(160) NOT NULL DEFAULT '',
    `agent_avatar` VARCHAR(500) NULL DEFAULT NULL,
    `date_debut` DATE NOT NULL,
    `date_fin` DATE NOT NULL,
    `motif` TEXT NULL,
    `statut` ENUM('en_attente','validee','refusee') NOT NULL DEFAULT 'en_attente',
    `reviewed_by` INT NULL DEFAULT NULL,
    `reviewed_at` DATETIME NULL DEFAULT NULL,
    `notif_seen` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_user` (`user_id`),
    KEY `idx_statut` (`statut`),
    KEY `idx_dates` (`date_debut`,`date_fin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
// Colonnes annexes : couleur d'événement + provenance (ex. absences auto générées
// par un arrêt de travail SAMS). Idempotent (MariaDB ADD COLUMN IF NOT EXISTS).
try { $db->exec("ALTER TABLE `lspd_absences` ADD COLUMN IF NOT EXISTS `color` VARCHAR(20) NULL DEFAULT NULL"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE `lspd_absences` ADD COLUMN IF NOT EXISTS `source` VARCHAR(30) NULL DEFAULT NULL"); } catch(Exception $e){}

$canManage = faction_acl_can($db,$uid,'LSPD','absence.manage');

// ── Helpers ───────────────────────────────────────────────────────────────────
/** Nombre de jours inclusifs entre deux dates (YYYY-MM-DD). */
function abs_days($a,$b){ try{$d1=new DateTime($a);$d2=new DateTime($b);return max(1,$d1->diff($d2)->days+1);}catch(Exception $e){return 1;} }

/** Notifie un agent via le système mail interne. Best-effort (mirroir lspd_payroll_notify). */
function abs_notify_mail(PDO $db, int $senderUid, int $receiverUid, string $subject, string $bodyHtml): bool {
    try {
        $r=$db->prepare("SELECT id,address FROM mail_accounts WHERE user_id=? AND is_active=1 ORDER BY id LIMIT 1");
        $r->execute([$receiverUid]); $recv=$r->fetch(PDO::FETCH_ASSOC);
        if(!$recv) return false;
        $s=$db->prepare("SELECT id,address,display_name FROM mail_accounts WHERE user_id=? AND is_active=1 ORDER BY id LIMIT 1");
        $s->execute([$senderUid]); $send=$s->fetch(PDO::FETCH_ASSOC);
        if(!$send) return false;
        $db->prepare("INSERT INTO mail_threads (subject) VALUES (?)")->execute([$subject]);
        $threadId=$db->lastInsertId();
        $db->prepare("INSERT INTO mail_messages (thread_id, from_account_id, from_address, from_name, to_addresses, cc_addresses, bcc_addresses, subject, body, body_type, reply_to_id, type, sent_at) VALUES (?,?,?,?,?,?,?,?,?,'html',NULL,'sent',NOW())")
           ->execute([$threadId,$send['id'],$send['address'],$send['display_name']?:'LSPD Absences',json_encode([$recv['address']]),json_encode([]),json_encode([]),$subject,$bodyHtml]);
        $msgId=$db->lastInsertId();
        $db->prepare("INSERT INTO mail_boxes (message_id,account_id,folder,is_read) VALUES (?,?,'sent',1)")->execute([$msgId,$send['id']]);
        $db->prepare("INSERT IGNORE INTO mail_boxes (message_id,account_id,folder) VALUES (?,?,'inbox')")->execute([$msgId,$recv['id']]);
        $db->prepare("INSERT INTO mail_notifications (user_id,account_id,message_id) VALUES (?,?,?)")->execute([$receiverUid,$recv['id'],$msgId]);
        $db->prepare("UPDATE mail_threads SET last_message_at=NOW() WHERE id=?")->execute([$threadId]);
        return true;
    } catch(Throwable $e){ return false; }
}

/** Corps HTML d'une notification mail d'absence. */
function abs_mail_body(string $etat, string $start, string $end, string $motif): string {
    $color = $etat==='validée' ? '#16a34a' : '#dc2626';
    $d1=date('d/m/Y',strtotime($start)); $d2=date('d/m/Y',strtotime($end));
    return '<div style="font-family:Arial,sans-serif;color:#0f172a">'
        . '<h2 style="color:#1d4ed8;margin:0 0 8px">Los Santos Police Department — Gestion des absences</h2>'
        . '<p>Bonjour,</p>'
        . '<p>Votre demande d\'absence a été <strong style="color:'.$color.'">'.htmlspecialchars($etat).'</strong>.</p>'
        . '<table style="border-collapse:collapse;margin:12px 0">'
        . '<tr><td style="padding:4px 12px 4px 0;color:#475569">Du</td><td style="font-weight:700">'.$d1.'</td></tr>'
        . '<tr><td style="padding:4px 12px 4px 0;color:#475569">Au</td><td style="font-weight:700">'.$d2.'</td></tr>'
        . '<tr><td style="padding:4px 12px 4px 0;color:#475569">Motif</td><td>'.nl2br(htmlspecialchars($motif)).'</td></tr>'
        . '</table>'
        . '<p style="color:#475569;font-size:13px">Message officiel automatique — Service RH LSPD.</p></div>';
}

// ── ACTIONS ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';

    if ($action==='declare') {
        $dd=trim($_POST['date_debut']??''); $df=trim($_POST['date_fin']??''); $motif=trim($_POST['motif']??'');
        $okD = $dd!=='' && $df!=='' && strtotime($dd)!==false && strtotime($df)!==false;
        if (!$okD || strtotime($dd)>strtotime($df)) { redirect(SITE_URL.'/pages/lspd/absence.php?flash=err_dates'); }
        if ($motif==='') { redirect(SITE_URL.'/pages/lspd/absence.php?flash=err_motif'); }
        $char = mdt_get_active_character($db);
        $charId    = $char['id']??null;
        $profileId = $char['profile_id']??null;
        $agentName = $char ? trim(($char['firstname']??'').' '.($char['lastname']??'')) : mdt_session_display_name();
        if ($agentName==='') $agentName=mdt_session_display_name();
        $agentAvatar = $char['avatar']??($_SESSION['user_avatar']??'');
        try {
            $db->prepare("INSERT INTO lspd_absences (user_id,character_id,profile_id,agent_name,agent_avatar,date_debut,date_fin,motif,statut) VALUES (?,?,?,?,?,?,?,?,'en_attente')")
               ->execute([$uid,$charId,$profileId,$agentName,$agentAvatar,date('Y-m-d',strtotime($dd)),date('Y-m-d',strtotime($df)),$motif]);
        } catch(Exception $e){}
        redirect(SITE_URL.'/pages/lspd/absence.php?flash=declared');
    }

    if ($action==='validate' || $action==='refuse') {
        if (!$canManage) { redirect(SITE_URL.'/pages/lspd/absence.php?flash=err_perm'); }
        $aid=(int)($_POST['absence_id']??0);
        $newStatut = $action==='validate' ? 'validee' : 'refusee';
        try {
            $s=$db->prepare("SELECT * FROM lspd_absences WHERE id=? LIMIT 1"); $s->execute([$aid]); $abs=$s->fetch(PDO::FETCH_ASSOC);
            if ($abs && $abs['statut']==='en_attente') {
                $db->prepare("UPDATE lspd_absences SET statut=?, reviewed_by=?, reviewed_at=NOW(), notif_seen=0 WHERE id=?")
                   ->execute([$newStatut,$uid,$aid]);
                // Notification mail best-effort
                $etat = $action==='validate' ? 'validée' : 'refusée';
                abs_notify_mail($db,$uid,(int)$abs['user_id'],'Absence '.$etat.' — LSPD',abs_mail_body($etat,$abs['date_debut'],$abs['date_fin'],(string)$abs['motif']));
            }
        } catch(Exception $e){}
        redirect(SITE_URL.'/pages/lspd/absence.php?flash='.($action==='validate'?'validated':'refused'));
    }
}

// ── DONNÉES ───────────────────────────────────────────────────────────────────
// Bannières in-app : mes demandes traitées et pas encore vues (avant marquage)
$myNew=[];
try{$s=$db->prepare("SELECT * FROM lspd_absences WHERE user_id=? AND notif_seen=0 AND statut IN ('validee','refusee') ORDER BY reviewed_at DESC");$s->execute([$uid]);$myNew=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}
// Marquer vues (le badge sidebar reflète le reste du MDT ; ici on est sur la page)
if($myNew){ try{$db->prepare("UPDATE lspd_absences SET notif_seen=1 WHERE user_id=? AND statut IN ('validee','refusee')")->execute([$uid]);}catch(Exception $e){} }

// Événements calendrier = absences validées
$calEvents=[];
try{
    $s=$db->query("SELECT id,agent_name,agent_avatar,date_debut,date_fin,motif,statut,color FROM lspd_absences WHERE statut='validee' ORDER BY date_debut ASC");
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
        $calEvents[]=[
            'id'=>(int)$r['id'],
            'name'=>$r['agent_name'],
            'avatar'=>$r['agent_avatar'],
            'start'=>$r['date_debut'],
            'end'=>$r['date_fin'],
            'motif'=>$r['motif'],
            'statut'=>$r['statut'],
            'color'=>$r['color'] ?? null,
            'days'=>abs_days($r['date_debut'],$r['date_fin']),
        ];
    }
}catch(Exception $e){}

// Demandes en attente
$pending=[];
try{$pending=$db->query("SELECT * FROM lspd_absences WHERE statut='en_attente' ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}

// Mon historique
$myAbs=[];
try{$s=$db->prepare("SELECT * FROM lspd_absences WHERE user_id=? ORDER BY date_debut DESC LIMIT 60");$s->execute([$uid]);$myAbs=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}

$STAT=[
    'en_attente'=>['En attente de validation','bg-yellow','#fde68a'],
    'validee'   =>['Validée','bg-green','#6ee7b7'],
    'refusee'   =>['Refusée','bg-red','#fca5a5'],
];
$flash=$_GET['flash']??'';
$FLASH=[
    'declared' =>['Demande d\'absence envoyée. Elle est en attente de validation.','success'],
    'validated'=>['Absence validée.','success'],
    'refused'  =>['Absence refusée.','success'],
    'err_dates'=>['Dates invalides : la date de fin doit être postérieure ou égale à la date de début.','error'],
    'err_motif'=>['Le motif est requis.','error'],
    'err_perm' =>['Vous n\'avez pas la permission de gérer les absences.','error'],
];

include __DIR__ . '/includes/header.php';

// Initiales pour l'avatar fallback
function abs_initials($name){ $p=preg_split('/\s+/',trim((string)$name)); $i=''; foreach($p as $w){ if($w!==''){$i.=mb_strtoupper(mb_substr($w,0,1));} if(mb_strlen($i)>=2)break; } return $i?:'?'; }
?>
<style>
.ab-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.4rem}
.ab-hero h1{font-family:'Montserrat',sans-serif;font-size:1.4rem;font-weight:900;color:#fff;margin:0;display:flex;align-items:center;gap:.6rem}
.ab-hero h1 i{color:var(--blue-l)}
.ab-hero .sub{font-size:.82rem;color:var(--txt-s);margin-top:.25rem}
/* Bannières */
.ab-banner{display:flex;align-items:center;gap:.7rem;padding:.8rem 1.05rem;border-radius:11px;margin-bottom:.7rem;font-size:.85rem;font-weight:500}
.ab-banner.ok{background:rgba(16,185,129,.09);border:1px solid rgba(16,185,129,.28);color:#6ee7b7}
.ab-banner.no{background:rgba(239,68,68,.09);border:1px solid rgba(239,68,68,.28);color:#fca5a5}
/* Calendrier */
.ab-cal{background:var(--bg-c);border:1px solid var(--bdr);border-radius:14px;padding:1.1rem 1.2rem 1.3rem;margin-bottom:1.4rem}
.ab-cal-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;gap:.6rem;flex-wrap:wrap}
.ab-cal-title{font-family:'Montserrat',sans-serif;font-size:1.05rem;font-weight:800;color:#fff;text-transform:capitalize;min-width:180px}
.ab-cal-nav{display:flex;gap:.35rem;align-items:center}
.ab-navbtn{width:34px;height:34px;border-radius:8px;background:rgba(37,99,235,.08);border:1px solid var(--bdr);color:var(--txt-s);cursor:pointer;display:grid;place-items:center;transition:all .15s;font-size:.8rem}
.ab-navbtn:hover{background:rgba(37,99,235,.18);color:var(--blue-xl)}
.ab-dow{display:grid;grid-template-columns:repeat(7,1fr);gap:.4rem;margin-bottom:.4rem}
.ab-dow span{text-align:center;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--txt-m)}
.ab-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:.4rem}
.ab-cell{min-height:92px;background:rgba(0,0,0,.18);border:1px solid rgba(255,255,255,.04);border-radius:9px;padding:.35rem;display:flex;flex-direction:column;gap:.22rem;overflow:hidden}
.ab-cell.out{opacity:.35}
.ab-cell.today{border-color:rgba(37,99,235,.5);box-shadow:inset 0 0 0 1px rgba(37,99,235,.25)}
.ab-daynum{font-size:.68rem;font-weight:700;color:var(--txt-s);align-self:flex-end}
.ab-cell.today .ab-daynum{color:var(--blue-l)}
.ab-ev{display:flex;align-items:center;gap:.28rem;padding:.14rem .3rem;border-radius:6px;cursor:pointer;font-size:.62rem;font-weight:600;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.28);color:#6ee7b7;transition:transform .12s}
.ab-ev:hover{transform:translateX(1px)}
.ab-ev .av{width:15px;height:15px;border-radius:50%;object-fit:cover;flex-shrink:0;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:grid;place-items:center;color:#fff;font-size:.5rem;font-weight:800;overflow:hidden}
.ab-ev .av img{width:100%;height:100%;object-fit:cover}
.ab-ev .nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ab-more{font-size:.58rem;color:var(--txt-m);padding-left:.2rem;cursor:pointer}
.ab-more:hover{color:var(--blue-l)}
/* Agenda (mobile) */
.ab-agenda{display:none;flex-direction:column;gap:.5rem}
.ab-ag-row{display:flex;align-items:center;gap:.7rem;padding:.6rem .8rem;background:rgba(0,0,0,.18);border:1px solid var(--bdr);border-radius:10px;cursor:pointer}
.ab-ag-av{width:34px;height:34px;border-radius:9px;object-fit:cover;flex-shrink:0;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:grid;place-items:center;color:#fff;font-weight:800;font-size:.8rem;overflow:hidden}
.ab-ag-av img{width:100%;height:100%;object-fit:cover}
.ab-empty{text-align:center;padding:2rem 1rem;color:var(--txt-m);font-size:.85rem}
.ab-empty i{font-size:1.8rem;opacity:.18;display:block;margin-bottom:.5rem}
/* Cartes demandes */
.ab-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:.9rem}
.ab-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1rem 1.1rem;transition:border-color .18s}
.ab-card:hover{border-color:var(--bdr-h)}
.ab-card-hd{display:flex;align-items:center;gap:.7rem;margin-bottom:.75rem}
.ab-card-av{width:44px;height:44px;border-radius:11px;object-fit:cover;flex-shrink:0;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:grid;place-items:center;color:#fff;font-weight:800;font-size:1rem;overflow:hidden;border:1px solid rgba(37,99,235,.3)}
.ab-card-av img{width:100%;height:100%;object-fit:cover}
.ab-card-name{font-weight:800;font-size:.9rem;color:#fff}
.ab-card-meta{font-size:.7rem;color:var(--txt-m);margin-top:.1rem}
.ab-card-dates{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.6rem}
.ab-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .6rem;border-radius:7px;font-size:.72rem;font-weight:600;background:rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.05);color:var(--txt-s)}
.ab-card-motif{font-size:.8rem;color:var(--txt-s);line-height:1.55;background:rgba(0,0,0,.18);border-radius:8px;padding:.55rem .7rem;margin-bottom:.7rem;white-space:pre-wrap}
.ab-card-actions{display:flex;gap:.5rem}
.ab-sec-h{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.11em;color:var(--txt-s);margin:0 0 .9rem;display:flex;align-items:center;gap:.5rem}
.ab-sec-h i{color:var(--blue-l)}
.ab-sec-h .cnt{font-size:.62rem;background:rgba(37,99,235,.15);color:var(--blue-xl);border-radius:10px;padding:.05rem .45rem;font-family:'IBM Plex Mono',monospace}
/* Historique perso */
.ab-hist{display:flex;flex-direction:column;gap:.5rem}
.ab-hrow{display:flex;align-items:center;gap:.8rem;padding:.7rem .9rem;background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;position:relative;border-left:3px solid var(--txt-m)}
.ab-hrow.s-validee{border-left-color:#10b981}.ab-hrow.s-refusee{border-left-color:#ef4444}.ab-hrow.s-en_attente{border-left-color:#f59e0b}
.ab-hrow .main{flex:1;min-width:0}
.ab-hrow .dt{font-weight:700;font-size:.85rem;color:var(--txt)}
.ab-hrow .mt{font-size:.72rem;color:var(--txt-m);margin-top:.15rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:700px){
  .ab-dow,.ab-grid{display:none}
  .ab-agenda{display:flex}
}
</style>

<?php if($flash && isset($FLASH[$flash])):?>
<div class="alert alert-<?php echo $FLASH[$flash][1];?>"><i class="fas fa-<?php echo $FLASH[$flash][1]==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo htmlspecialchars($FLASH[$flash][0]);?></div>
<?php endif;?>

<?php foreach($myNew as $mn): $st=$STAT[$mn['statut']];?>
<div class="ab-banner <?php echo $mn['statut']==='validee'?'ok':'no';?>">
    <i class="fas fa-<?php echo $mn['statut']==='validee'?'circle-check':'circle-xmark';?>"></i>
    Votre demande d'absence du <?php echo date('d/m/Y',strtotime($mn['date_debut']));?> au <?php echo date('d/m/Y',strtotime($mn['date_fin']));?> a été <strong><?php echo $mn['statut']==='validee'?'validée':'refusée';?></strong>.
</div>
<?php endforeach;?>

<!-- HERO -->
<div class="ab-hero">
    <div>
        <h1><i class="fas fa-calendar-xmark"></i> Gestion des absences</h1>
        <div class="sub">Consultez les absences des agents et déclarez vos prochaines indisponibilités.</div>
    </div>
    <button class="btn btn-primary" onclick="LSPD.openModal('absDeclareModal')"><i class="fas fa-plus"></i> Déclarer une absence</button>
</div>

<!-- CALENDRIER -->
<div class="ab-cal">
    <div class="ab-cal-top">
        <div class="ab-cal-title" id="abCalTitle">—</div>
        <div class="ab-cal-nav">
            <button class="ab-navbtn" onclick="absPrev()" title="Mois précédent"><i class="fas fa-chevron-left"></i></button>
            <button class="btn btn-ghost btn-sm" onclick="absToday()"><i class="fas fa-calendar-day"></i> Aujourd'hui</button>
            <button class="ab-navbtn" onclick="absNext()" title="Mois suivant"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
    <div class="ab-dow"><span>Lun</span><span>Mar</span><span>Mer</span><span>Jeu</span><span>Ven</span><span>Sam</span><span>Dim</span></div>
    <div class="ab-grid" id="abGrid"></div>
    <div class="ab-agenda" id="abAgenda"></div>
</div>

<!-- DEMANDES EN ATTENTE -->
<div class="card">
    <div class="ab-sec-h"><i class="fas fa-hourglass-half"></i> Demandes en attente <?php if($pending):?><span class="cnt"><?php echo count($pending);?></span><?php endif;?></div>
    <?php if(empty($pending)):?>
    <div class="ab-empty"><i class="fas fa-inbox"></i>Aucune demande en attente.</div>
    <?php else:?>
    <div class="ab-cards">
        <?php foreach($pending as $p): $days=abs_days($p['date_debut'],$p['date_fin']); $init=abs_initials($p['agent_name']); $av=$p['agent_avatar']??'';?>
        <div class="ab-card">
            <div class="ab-card-hd">
                <div class="ab-card-av"><?php if($av):?><img src="<?php echo htmlspecialchars(preg_match('#^https?://#',$av)?$av:SITE_URL.$av);?>" alt=""><?php else:?><?php echo htmlspecialchars($init);?><?php endif;?></div>
                <div style="min-width:0"><div class="ab-card-name"><?php echo htmlspecialchars($p['agent_name']?:'Agent');?></div><div class="ab-card-meta"><i class="fas fa-clock" style="opacity:.5"></i> Demandé le <?php echo date('d/m/Y',strtotime($p['created_at']));?></div></div>
            </div>
            <div class="ab-card-dates">
                <span class="ab-chip"><i class="fas fa-play" style="color:var(--blue-l)"></i> <?php echo date('d/m/Y',strtotime($p['date_debut']));?></span>
                <span class="ab-chip"><i class="fas fa-flag-checkered" style="color:var(--blue-l)"></i> <?php echo date('d/m/Y',strtotime($p['date_fin']));?></span>
                <span class="ab-chip"><i class="fas fa-hourglass" style="color:var(--blue-l)"></i> <?php echo $days;?> jour<?php echo $days>1?'s':'';?></span>
            </div>
            <div class="ab-card-motif"><?php echo htmlspecialchars($p['motif']?:'—');?></div>
            <?php if($canManage):?>
            <div class="ab-card-actions">
                <form method="POST" style="flex:1;margin:0"><input type="hidden" name="action" value="validate"><input type="hidden" name="absence_id" value="<?php echo (int)$p['id'];?>"><button type="submit" class="btn btn-success btn-sm" style="width:100%;justify-content:center"><i class="fas fa-check"></i> Valider</button></form>
                <form method="POST" style="flex:1;margin:0"><input type="hidden" name="action" value="refuse"><input type="hidden" name="absence_id" value="<?php echo (int)$p['id'];?>"><button type="submit" class="btn btn-danger btn-sm" style="width:100%;justify-content:center"><i class="fas fa-xmark"></i> Refuser</button></form>
            </div>
            <?php else:?>
            <div style="font-size:.72rem;color:var(--txt-m)"><span class="badge bg-yellow"><i class="fas fa-hourglass-half"></i> En attente de validation</span></div>
            <?php endif;?>
        </div>
        <?php endforeach;?>
    </div>
    <?php endif;?>
</div>

<!-- MON HISTORIQUE -->
<div class="card">
    <div class="ab-sec-h"><i class="fas fa-clock-rotate-left"></i> Mes absences</div>
    <?php if(empty($myAbs)):?>
    <div class="ab-empty"><i class="fas fa-calendar"></i>Vous n'avez déclaré aucune absence.</div>
    <?php else:?>
    <div class="ab-hist">
        <?php foreach($myAbs as $m): $st=$STAT[$m['statut']]; $days=abs_days($m['date_debut'],$m['date_fin']);?>
        <div class="ab-hrow s-<?php echo $m['statut'];?>">
            <div class="main">
                <div class="dt">Du <?php echo date('d/m/Y',strtotime($m['date_debut']));?> au <?php echo date('d/m/Y',strtotime($m['date_fin']));?> <span style="color:var(--txt-m);font-weight:500">· <?php echo $days;?> jour<?php echo $days>1?'s':'';?></span></div>
                <div class="mt"><?php echo htmlspecialchars($m['motif']?:'—');?></div>
            </div>
            <span class="badge <?php echo $st[1];?>"><?php echo $st[0];?></span>
        </div>
        <?php endforeach;?>
    </div>
    <?php endif;?>
</div>

<!-- MODAL DÉCLARER -->
<div id="absDeclareModal" class="modal-ov hidden">
<div class="modal modal-sm">
<div class="modal-hd"><h2><i class="fas fa-calendar-plus"></i> Déclarer une absence</h2><button class="modal-cl" onclick="LSPD.closeModal('absDeclareModal')">×</button></div>
<form method="POST"><div class="modal-bd">
    <input type="hidden" name="action" value="declare">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Date de début *</label><input type="date" name="date_debut" id="absStart" class="form-input" onchange="absSyncMin()"></div>
        <div class="form-group"><label class="form-label">Date de fin *</label><input type="date" name="date_fin" id="absEnd" class="form-input"></div>
        <div class="form-group form-full"><label class="form-label">Motif *</label><textarea name="motif" class="form-textarea" rows="4" required placeholder="Vacances, Hospitalisation, Déplacement, Raisons personnelles..."></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('absDeclareModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Envoyer la demande</button></div>
</form></div></div>

<!-- MODAL DÉTAIL -->
<div id="absDetailModal" class="modal-ov hidden">
<div class="modal modal-sm">
<div class="modal-hd"><h2><i class="fas fa-calendar-check"></i> Détail de l'absence</h2><button class="modal-cl" onclick="LSPD.closeModal('absDetailModal')">×</button></div>
<div class="modal-bd">
    <div style="display:flex;align-items:center;gap:.8rem;margin-bottom:1rem">
        <div class="ab-card-av" id="adAv" style="width:52px;height:52px"></div>
        <div><div class="ab-card-name" id="adName" style="font-size:1rem">—</div><div id="adStatut" style="margin-top:.3rem"></div></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.7rem">
        <div class="ab-chip" style="justify-content:flex-start"><i class="fas fa-play" style="color:var(--blue-l)"></i> Début : <strong id="adStart" style="margin-left:.2rem">—</strong></div>
        <div class="ab-chip" style="justify-content:flex-start"><i class="fas fa-flag-checkered" style="color:var(--blue-l)"></i> Fin : <strong id="adEnd" style="margin-left:.2rem">—</strong></div>
        <div class="ab-chip" style="justify-content:flex-start;grid-column:1/-1"><i class="fas fa-hourglass" style="color:var(--blue-l)"></i> Durée : <strong id="adDays" style="margin-left:.2rem">—</strong></div>
    </div>
    <div><div class="form-label" style="margin-bottom:.3rem">Motif</div><div class="ab-card-motif" id="adMotif" style="margin:0">—</div></div>
</div>
<div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('absDetailModal')">Fermer</button></div>
</div></div>

<script>
const ABS_EVENTS = <?php echo json_encode($calEvents, JSON_HEX_APOS|JSON_HEX_TAG|JSON_UNESCAPED_UNICODE);?>;
const ABS_SITE = <?php echo json_encode(SITE_URL);?>;
const ABS_MONTHS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
let absView = new Date(); absView.setDate(1);

function absEsc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
function absAvatarUrl(a){ if(!a) return ''; return /^https?:\/\//i.test(a)?a:ABS_SITE+a; }
function absInitials(n){ const p=(n||'').trim().split(/\s+/); let i=''; for(const w of p){ if(w){i+=w[0].toUpperCase();} if(i.length>=2)break; } return i||'?'; }
function absToStr(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
function absAvHtml(ev,cls){ const url=absAvatarUrl(ev.avatar); return url ? '<img src="'+absEsc(url)+'" alt="">' : absEsc(absInitials(ev.name)); }
/* Style d'un événement coloré (ex. arrêt de travail SAMS = violet), sinon vert par défaut. */
function absEvStyle(ev){ return ev && ev.color ? ' style="background:'+ev.color+'22;border-color:'+ev.color+'66;color:'+ev.color+'"' : ''; }

function absEventsForDay(dayStr){ return ABS_EVENTS.filter(e=> dayStr>=e.start && dayStr<=e.end ); }

function absRender(){
    const y=absView.getFullYear(), m=absView.getMonth();
    document.getElementById('abCalTitle').textContent = ABS_MONTHS[m]+' '+y;
    // Grille : lundi = premier jour
    const first=new Date(y,m,1);
    let startDow=(first.getDay()+6)%7; // 0=lundi
    const daysInMonth=new Date(y,m+1,0).getDate();
    const prevDays=new Date(y,m,0).getDate();
    const todayStr=absToStr(new Date());
    let cells=[];
    for(let i=0;i<startDow;i++){ cells.push({n:prevDays-startDow+1+i,out:true,y,m:m-1}); }
    for(let d=1;d<=daysInMonth;d++){ cells.push({n:d,out:false,y,m}); }
    while(cells.length%7!==0){ cells.push({n:cells.length-(startDow+daysInMonth)+1,out:true}); }
    let html='';
    cells.forEach(c=>{
        let dayStr='';
        if(!c.out){ dayStr=absToStr(new Date(y,m,c.n)); }
        const evs=c.out?[]:absEventsForDay(dayStr);
        const isToday=dayStr===todayStr;
        html+='<div class="ab-cell'+(c.out?' out':'')+(isToday?' today':'')+'">';
        html+='<div class="ab-daynum">'+c.n+'</div>';
        evs.slice(0,3).forEach(ev=>{
            html+='<div class="ab-ev"'+absEvStyle(ev)+' onclick="absOpen('+ev.id+')" title="'+absEsc(ev.name)+(ev.motif?' — '+absEsc(ev.motif):'')+'"><span class="av">'+absAvHtml(ev)+'</span><span class="nm">'+absEsc(ev.name)+'</span></div>';
        });
        if(evs.length>3){ html+='<div class="ab-more" onclick="absOpen('+evs[3].id+')">+'+(evs.length-3)+' autres</div>'; }
        html+='</div>';
    });
    document.getElementById('abGrid').innerHTML=html;
    absRenderAgenda(y,m);
}

function absRenderAgenda(y,m){
    // Événements dont la période croise le mois affiché
    const monthStart=absToStr(new Date(y,m,1));
    const monthEnd=absToStr(new Date(y,m+1,0));
    const list=ABS_EVENTS.filter(e=> e.start<=monthEnd && e.end>=monthStart ).sort((a,b)=>a.start<b.start?-1:1);
    const el=document.getElementById('abAgenda');
    if(!list.length){ el.innerHTML='<div class="ab-empty"><i class="fas fa-calendar"></i>Aucune absence ce mois-ci.</div>'; return; }
    el.innerHTML=list.map(ev=>{
        const rowStyle = ev.color ? ' style="border-left:3px solid '+ev.color+'"' : '';
        const badge = ev.color
            ? '<span class="badge" style="background:'+ev.color+'22;color:'+ev.color+';border:1px solid '+ev.color+'66">'+absEsc(ev.motif||'Absence')+'</span>'
            : '<span class="badge bg-green">Validée</span>';
        return '<div class="ab-ag-row"'+rowStyle+' onclick="absOpen('+ev.id+')"><div class="ab-ag-av">'+absAvHtml(ev)+'</div>'
            +'<div style="flex:1;min-width:0"><div style="font-weight:700;font-size:.85rem">'+absEsc(ev.name)+'</div>'
            +'<div style="font-size:.72rem;color:var(--txt-m)">'+absFmt(ev.start)+' → '+absFmt(ev.end)+' · '+ev.days+' j</div></div>'
            +badge+'</div>';
    }).join('');
}

function absFmt(s){ const p=s.split('-'); return p[2]+'/'+p[1]+'/'+p[0]; }
function absPrev(){ absView.setMonth(absView.getMonth()-1); absRender(); }
function absNext(){ absView.setMonth(absView.getMonth()+1); absRender(); }
function absToday(){ absView=new Date(); absView.setDate(1); absRender(); }

function absOpen(id){
    const ev=ABS_EVENTS.find(e=>e.id===id); if(!ev) return;
    const av=document.getElementById('adAv'); const url=absAvatarUrl(ev.avatar);
    av.innerHTML = url ? '<img src="'+absEsc(url)+'" alt="">' : absEsc(absInitials(ev.name));
    document.getElementById('adName').textContent=ev.name||'Agent';
    document.getElementById('adStatut').innerHTML = ev.color
        ? '<span class="badge" style="background:'+ev.color+'22;color:'+ev.color+';border:1px solid '+ev.color+'66"><i class="fas fa-notes-medical"></i> '+absEsc(ev.motif||'Absence')+'</span>'
        : '<span class="badge bg-green"><i class="fas fa-circle-check"></i> Validée</span>';
    document.getElementById('adStart').textContent=absFmt(ev.start);
    document.getElementById('adEnd').textContent=absFmt(ev.end);
    document.getElementById('adDays').textContent=ev.days+' jour'+(ev.days>1?'s':'');
    document.getElementById('adMotif').textContent=ev.motif||'—';
    LSPD.openModal('absDetailModal');
}

// La date de fin ne peut être antérieure à la date de début
function absSyncMin(){ const s=document.getElementById('absStart').value; const e=document.getElementById('absEnd'); if(s){ e.min=s; if(e.value && e.value<s) e.value=s; } }

document.addEventListener('DOMContentLoaded', absRender);
</script>

<?php include __DIR__ . '/includes/footer.php';?>
