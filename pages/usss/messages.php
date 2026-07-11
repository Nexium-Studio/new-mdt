<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$companyName = '🇺🇸 United States Secret Service';
$companyColor = '#1e3a8a';
$companyFaction = 'USSS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Messagerie Sécurisée';
$db = Database::getInstance()->getConnection();
$message = ''; $msgType = '';

$userId = $_SESSION['user_id'];

// ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Envoyer message
    if ($action === 'send_message') {
        try {
            $db->prepare("
                INSERT INTO usss_messages 
                (expediteur_id, destinataire_id, niveau_classification, sujet, message)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $userId,
                (int)$_POST['destinataire_id'],
                $_POST['niveau_classification'] ?? 'NORMAL',
                sanitize($_POST['sujet']),
                sanitize($_POST['message'])
            ]);
            
            $message = "✅ Message envoyé";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Marquer comme lu
    if ($action === 'mark_read') {
        try {
            $messageId = (int)$_POST['message_id'];
            
            $db->prepare("UPDATE usss_messages SET lu = 1, date_lecture = NOW() WHERE id = ? AND destinataire_id = ?")
               ->execute([$messageId, $userId]);
            
            $message = "✅ Message marqué comme lu";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Boîte de réception
$boiteReception = $db->query("
    SELECT 
        m.*,
        exp.username as expediteur_nom
    FROM usss_messages m
    JOIN users exp ON m.expediteur_id = exp.id
    WHERE m.destinataire_id = $userId
    ORDER BY m.created_at DESC
")->fetchAll();

// Messages envoyés
$messagesEnvoyes = $db->query("
    SELECT 
        m.*,
        dest.username as destinataire_nom
    FROM usss_messages m
    JOIN users dest ON m.destinataire_id = dest.id
    WHERE m.expediteur_id = $userId
    ORDER BY m.created_at DESC
")->fetchAll();

// Utilisateurs disponibles
$utilisateurs = $db->query("
    SELECT u.id, u.username, a.nom_rp, a.prenom_rp, a.badge_number
    FROM users u
    LEFT JOIN usss_agents a ON u.id = a.user_id
    WHERE u.faction = 'USSS' AND u.id != $userId
    ORDER BY u.username
")->fetchAll();

// Stats
$stats = [
    'non_lus' => count(array_filter($boiteReception, fn($m) => !$m['lu'])),
    'total_recus' => count($boiteReception),
    'total_envoyes' => count($messagesEnvoyes),
];

$classificationColors = [
    'NORMAL' => '#64748b',
    'CONFIDENTIEL' => '#3b82f6',
    'SECRET' => '#f59e0b',
    'TOP_SECRET' => '#dc2626'
];

$onglet = $_GET['onglet'] ?? 'reception';

if (isset($_GET['msg'])) { $message=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }
$tab=$_GET['tab']??'recus';
$userId=$_SESSION['user_id'];
$messagesRecus=[];$messagesEnvoyes=[];
try{$messagesRecus=$db->query("SELECT m.*,u.username as exp_name FROM usss_messages m JOIN users u ON m.expediteur_id=u.id WHERE m.destinataire_id=$userId ORDER BY m.created_at DESC LIMIT 30")->fetchAll();}catch(Exception $e){}
try{$messagesEnvoyes=$db->query("SELECT m.*,u.username as dest_name FROM usss_messages m JOIN users u ON m.destinataire_id=u.id WHERE m.expediteur_id=$userId ORDER BY m.created_at DESC LIMIT 30")->fetchAll();}catch(Exception $e){}
$nonLus=count(array_filter($messagesRecus,fn($m)=>!$m['lu']));
$destinataires=[];try{$destinataires=$db->query("SELECT id,username FROM users WHERE faction='USSS' AND id!=$userId ORDER BY username")->fetchAll();}catch(Exception $e){}

$habCfg=['NORMAL'=>['b-slate','Normal'],'CONFIDENTIEL'=>['b-blue','Confidentiel'],'SECRET'=>['b-amber','Secret'],'TOP_SECRET'=>['b-red','Top Secret']];

include __DIR__ . '/includes/header.php';
?>
<?php if($message):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $message;?></div><?php endif;?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.65rem">
  <div style="display:flex;gap:0;background:var(--bg-c);border:1px solid var(--bdr);border-radius:9px;padding:.22rem">
    <?php foreach(['recus'=>'Reçus','envoyes'=>'Envoyés'] as $t=>$l):?>
    <a href="?tab=<?php echo $t;?>" class="btn <?php echo $tab===$t?'btn-primary':'btn-ghost';?> btn-sm" style="border-radius:7px">
      <?php echo $l;?><?php if($t==='recus'&&$nonLus>0):?> <span class="badge b-red" style="font-size:.55rem"><?php echo $nonLus;?></span><?php endif;?>
    </a>
    <?php endforeach;?>
  </div>
  <button onclick="USSS.openModal('newMsgModal')" class="btn btn-primary btn-sm"><i class="fas fa-pen"></i> Nouveau message</button>
</div>

<?php $msgs=$tab==='recus'?$messagesRecus:$messagesEnvoyes; ?>
<?php if(empty($msgs)):?>
<div class="card" style="text-align:center;padding:3.5rem;color:var(--txt-m)"><i class="fas fa-envelope-open" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucun message</div>
<?php else: foreach($msgs as $m):[$hb,$hl]=$habCfg[$m['niveau_classification']]??['b-slate','Normal'];$isNew=$tab==='recus'&&!$m['lu'];?>
<div style="background:<?php echo $isNew?'var(--bg-e)':'var(--bg-c)';?>;border:1px solid <?php echo $isNew?'var(--bdr-b)':'var(--bdr)';?>;border-radius:10px;padding:1rem 1.25rem;margin-bottom:.55rem;transition:border-color .15s" onmouseover="this.style.borderColor='var(--bdr-h)'" onmouseout="this.style.borderColor='<?php echo $isNew?'var(--bdr-b)':'var(--bdr)';?>'">
  <div style="display:flex;align-items:flex-start;gap:.85rem">
    <div style="width:36px;height:36px;border-radius:8px;background:rgba(26,86,219,.12);border:1px solid rgba(26,86,219,.2);display:flex;align-items:center;justify-content:center;font-family:'Barlow Condensed',sans-serif;font-weight:700;color:var(--blue-xl);flex-shrink:0">
      <?php echo strtoupper(substr($tab==='recus'?$m['exp_name']:$m['dest_name'],0,1));?>
    </div>
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;margin-bottom:.22rem">
        <span style="font-weight:<?php echo $isNew?700:500;?>;font-size:.85rem"><?php echo $tab==='recus'?htmlspecialchars($m['exp_name']):('→ '.htmlspecialchars($m['dest_name']));?></span>
        <span class="badge <?php echo $hb;?>" style="font-size:.58rem"><?php echo $hl;?></span>
        <?php if($isNew):?><span class="badge b-blue" style="font-size:.55rem">Nouveau</span><?php endif;?>
        <span class="mono" style="font-size:.62rem;color:var(--txt-m);margin-left:auto"><?php echo date('d.m.Y H:i',strtotime($m['created_at']));?></span>
      </div>
      <div style="font-weight:600;font-size:.85rem;margin-bottom:.25rem"><?php echo htmlspecialchars($m['sujet']);?></div>
      <div style="font-size:.79rem;color:var(--txt-s);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical"><?php echo htmlspecialchars(mb_substr($m['message'],0,150));?></div>
    </div>
  </div>
</div>
<?php endforeach; endif;?>

<!-- MODAL NOUVEAU MESSAGE -->
<div id="newMsgModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Nouveau Message</h2><button class="modal-cl" onclick="USSS.closeModal('newMsgModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="send_message">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Destinataire *</label>
      <select name="destinataire_id" class="form-select" required><option value="">Sélectionner…</option><?php foreach($destinataires as $d):?><option value="<?php echo $d['id'];?>"><?php echo htmlspecialchars($d['username']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Classification</label>
      <select name="niveau_classification" class="form-select"><?php foreach($habCfg as $k=>[$b,$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group form-full"><label class="form-label">Sujet *</label><input type="text" name="sujet" class="form-input" required></div>
    <div class="form-group form-full"><label class="form-label">Message *</label><textarea name="message" class="form-textarea" required style="min-height:120px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('newMsgModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Envoyer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>