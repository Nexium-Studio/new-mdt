<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');

$pageTitle = 'PPA Agent';
$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);
$msg = ''; $msgType = '';

// ── Helper log ──────────────────────────────────────────────────────────────
if (!function_exists('lssd_log')) {
    function lssd_log(PDO $db, int $userId, string $action, string $page, ?int $targetId=null, ?string $targetLabel=null, ?string $details=null): void {
        try {
            $db->prepare("INSERT INTO lssd_activity_logs (user_id,username,faction_rank,action,page,target_id,target_label,details) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$userId, $_SESSION['user_username']??'', $_SESSION['user_faction_rank']??'', $action, $page, $targetId, $targetLabel, $details]);
        } catch(Exception $e){ error_log('lssd_log: '.$e->getMessage()); }
    }
}

// ── Discord Webhook ──────────────────────────────────────────────────────────
function sendPPAWebhook(string $url, array $embed): void {
    if (empty($url)) return;
    try {
        $payload = json_encode(['embeds' => [$embed]]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch); curl_close($ch);
    } catch(Exception $e){}
}

// Récupérer l'URL webhook depuis admin_settings
$webhookUrl = '';
try { $r=$db->query("SELECT setting_value FROM admin_settings WHERE setting_key='discord_webhook_ppa' LIMIT 1")->fetch(); $webhookUrl=$r['setting_value']??''; } catch(Exception $e){}

// ── Grades LSSD (même logique que effectifs.php) ─────────────────────────────
$RANKS = [];
try {
    $s=$db->prepare("SELECT fg.name FROM faction_grades fg JOIN factions f ON f.id=fg.faction_id WHERE f.faction_key='LSSD' ORDER BY fg.rank_order ASC");
    $s->execute(); $RANKS=array_column($s->fetchAll(),'name');
} catch(Exception $e){}
if (empty($RANKS)) {
    // Essai 2 via faction_id
    try {
        $fid=$db->query("SELECT id FROM factions WHERE faction_key='LSSD' LIMIT 1")->fetchColumn();
        if ($fid){ $s2=$db->prepare("SELECT name FROM faction_grades WHERE faction_id=? ORDER BY rank_order ASC"); $s2->execute([$fid]); $RANKS=array_column($s2->fetchAll(),'name'); }
    } catch(Exception $e){}
}
if (empty($RANKS)) $RANKS=['Sheriff','Undersheriff','Assistant Sheriff','Captain','Lieutenant','Lieutenant','Sergeant','Sergeant','Deputy Sheriff','Deputy Sheriff','Deputy Sheriff','Deputy Sheriff Trainee'];

$RLEVEL = [];
foreach ($RANKS as $idx=>$n) $RLEVEL[$n] = count($RANKS) - $idx;
$maxLvl = count($RANKS);

// Grade du user connecté (depuis DB)
$myGrade = '';
try { $s=$db->prepare("SELECT faction_rank FROM users WHERE id=? LIMIT 1"); $s->execute([$uid]); $myGrade=$s->fetchColumn()?:''; } catch(Exception $e){}
$myLevel = $RLEVEL[$myGrade] ?? 0;
$isStaff = hasRole(['*','Administrateur','Développeur','Staff', 'LSSD']);
// Sergeant I = seuil 45%
$sgtThreshold = $maxLvl > 0 ? (int)ceil($maxLvl * 0.40) : 5;
$canManage    = $isStaff || $myLevel >= $sgtThreshold;

// ── Migrations silencieuses ──────────────────────────────────────────────────
try { $db->exec("CREATE TABLE IF NOT EXISTS lssd_ppa_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nom VARCHAR(100) NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch(Exception $e){}

try { $db->exec("CREATE TABLE IF NOT EXISTS lssd_ppa_armes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id INT UNSIGNED NOT NULL,
    nom VARCHAR(150) NOT NULL,
    numero_serie VARCHAR(100) DEFAULT NULL,
    etat VARCHAR(100) DEFAULT 'Bon état',
    stock_munitions INT DEFAULT 0,
    statut ENUM('libre','en_attente','en_service') NOT NULL DEFAULT 'libre',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(id),
    KEY(category_id), KEY(statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch(Exception $e){}

try { $db->exec("CREATE TABLE IF NOT EXISTS lssd_ppa_sorties (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    arme_id INT UNSIGNED NOT NULL,
    agent_id INT NOT NULL,
    periode_debut DATETIME NOT NULL,
    periode_fin DATETIME DEFAULT NULL,
    raison TEXT DEFAULT NULL,
    statut ENUM('en_attente','valide','refuse','rendu') NOT NULL DEFAULT 'en_attente',
    valide_par INT DEFAULT NULL,
    valide_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(id),
    KEY(arme_id), KEY(agent_id), KEY(statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch(Exception $e){}

try { $db->exec("CREATE TABLE IF NOT EXISTS lssd_ppa_retours (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sortie_id INT UNSIGNED NOT NULL,
    arme_id INT UNSIGNED NOT NULL,
    etat_retour VARCHAR(100) DEFAULT 'Bon état',
    munitions_retour INT DEFAULT 0,
    lieu VARCHAR(200) DEFAULT NULL,
    recupere_par INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch(Exception $e){}

// Pré-peupler catégories par défaut
try {
    $cnt=$db->query("SELECT COUNT(*) FROM lssd_ppa_categories")->fetchColumn();
    if ($cnt==0){
        foreach(['Bean Bag','PPA I','PPA II','PPA III','PPA IV','PPA V'] as $cat)
            $db->prepare("INSERT INTO lssd_ppa_categories (nom,created_by) VALUES (?,?)")->execute([$cat,$uid]);
    }
} catch(Exception $e){}

// ── POST ACTIONS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action']??'';

    // ── Créer catégorie ─────────────────────────────────────────────────────
    if ($action==='add_category' && $canManage) {
        $nom = trim($_POST['nom']??'');
        if ($nom) {
            try {
                $db->prepare("INSERT INTO lssd_ppa_categories (nom,created_by) VALUES (?,?)")->execute([$nom,$uid]);
                lssd_log($db,$uid,'ppa_add_category','ppa-agent',(int)$db->lastInsertId(),$nom);
                $msg="Catégorie « $nom » créée."; $msgType='success';
            } catch(Exception $e){ $msg='Erreur: '.$e->getMessage(); $msgType='error'; }
        }
    }

    // ── Ajouter arme ────────────────────────────────────────────────────────
    if ($action==='add_arme' && $canManage) {
        try {
            $db->prepare("INSERT INTO lssd_ppa_armes (category_id,nom,numero_serie,etat,stock_munitions,statut,created_by) VALUES (?,?,?,?,?,'libre',?)")
               ->execute([(int)$_POST['category_id'],trim($_POST['nom']),trim($_POST['numero_serie']??''),trim($_POST['etat']??'Bon état'),(int)($_POST['stock_munitions']??0),$uid]);
            $aid=(int)$db->lastInsertId();
            lssd_log($db,$uid,'ppa_add_arme','ppa-agent',$aid,trim($_POST['nom']),'Série: '.trim($_POST['numero_serie']??''));
            $msg='Arme ajoutée.'; $msgType='success';
        } catch(Exception $e){ $msg='Erreur: '.$e->getMessage(); $msgType='error'; }
    }

    // ── Demande de sortie ────────────────────────────────────────────────────
    if ($action==='demande_sortie') {
        $armeId=(int)$_POST['arme_id'];
        try {
            // Vérifier que l'arme est libre
            $arme=$db->prepare("SELECT a.*,c.nom cat_nom FROM lssd_ppa_armes a JOIN lssd_ppa_categories c ON c.id=a.category_id WHERE a.id=? AND a.statut='libre' LIMIT 1");
            $arme->execute([$armeId]); $arme=$arme->fetch();
            if (!$arme) throw new Exception("Arme indisponible.");
            $agentRow=$db->prepare("SELECT username FROM users WHERE id=? LIMIT 1"); $agentRow->execute([(int)$_POST['agent_id']]); $agentName=$agentRow->fetchColumn();
            $db->prepare("INSERT INTO lssd_ppa_sorties (arme_id,agent_id,periode_debut,periode_fin,raison) VALUES (?,?,?,?,?)")
               ->execute([$armeId,(int)$_POST['agent_id'],$_POST['periode_debut'],$_POST['periode_fin']??null,trim($_POST['raison']??'')]);
            $sid=(int)$db->lastInsertId();
            $db->prepare("UPDATE lssd_ppa_armes SET statut='en_attente' WHERE id=?")->execute([$armeId]);
            lssd_log($db,$uid,'ppa_demande_sortie','ppa-agent',$armeId,$arme['nom'],"Agent: $agentName");
            // Webhook Discord
            sendPPAWebhook($webhookUrl,[
                'title'=>'🔫 Demande de sortie PPA',
                'color'=>0x95a5a6,
                'fields'=>[
                    ['name'=>'Agent demandeur','value'=>$agentName,'inline'=>true],
                    ['name'=>'Arme','value'=>$arme['nom'],'inline'=>true],
                    ['name'=>'Catégorie','value'=>$arme['cat_nom'],'inline'=>true],
                    ['name'=>'Début','value'=>$_POST['periode_debut'],'inline'=>true],
                    ['name'=>'Fin','value'=>$_POST['periode_fin']??'Non définie','inline'=>true],
                    ['name'=>'Raison','value'=>trim($_POST['raison']??'—'),'inline'=>false],
                ],
                'timestamp'=>date('c'),
            ]);
            $msg='Demande transmise — en attente de validation.'; $msgType='success';
        } catch(Exception $e){ $msg='Erreur: '.$e->getMessage(); $msgType='error'; }
    }

    // ── Valider demande ──────────────────────────────────────────────────────
    if ($action==='valider_sortie' && $canManage) {
        $sid=(int)$_POST['sortie_id'];
        try {
            $sortie=$db->prepare("SELECT s.*,u.username agent_nom,a.nom arme_nom FROM lssd_ppa_sorties s JOIN users u ON u.id=s.agent_id JOIN lssd_ppa_armes a ON a.id=s.arme_id WHERE s.id=? LIMIT 1");
            $sortie->execute([$sid]); $sortie=$sortie->fetch();
            if (!$sortie) throw new Exception("Demande introuvable.");
            $db->prepare("UPDATE lssd_ppa_sorties SET statut='valide',valide_par=?,valide_at=NOW() WHERE id=?")->execute([$uid,$sid]);
            $db->prepare("UPDATE lssd_ppa_armes SET statut='en_service' WHERE id=?")->execute([$sortie['arme_id']]);
            lssd_log($db,$uid,'ppa_valider_sortie','ppa-agent',$sortie['arme_id'],$sortie['arme_nom'],"Agent: ".$sortie['agent_nom']);
            $msg='Sortie validée.'; $msgType='success';
        } catch(Exception $e){ $msg='Erreur: '.$e->getMessage(); $msgType='error'; }
    }

    // ── Refuser demande ──────────────────────────────────────────────────────
    if ($action==='refuser_sortie' && $canManage) {
        $sid=(int)$_POST['sortie_id'];
        try {
            $sortie=$db->prepare("SELECT s.*,a.nom arme_nom FROM lssd_ppa_sorties s JOIN lssd_ppa_armes a ON a.id=s.arme_id WHERE s.id=? LIMIT 1");
            $sortie->execute([$sid]); $sortie=$sortie->fetch();
            if (!$sortie) throw new Exception("Demande introuvable.");
            $db->prepare("UPDATE lssd_ppa_sorties SET statut='refuse' WHERE id=?")->execute([$sid]);
            $db->prepare("UPDATE lssd_ppa_armes SET statut='libre' WHERE id=?")->execute([$sortie['arme_id']]);
            lssd_log($db,$uid,'ppa_refuser_sortie','ppa-agent',$sortie['arme_id'],$sortie['arme_nom']);
            $msg='Demande refusée — arme remise disponible.'; $msgType='success';
        } catch(Exception $e){ $msg='Erreur: '.$e->getMessage(); $msgType='error'; }
    }

    // ── Retour d'arme ────────────────────────────────────────────────────────
    if ($action==='retour_arme') {
        $armeId=(int)$_POST['arme_id'];
        $sortieId=(int)$_POST['sortie_id'];
        try {
            // Vérifier droits : canManage OU agent ayant fait la demande
            $sortie=$db->prepare("SELECT s.*,u.username agent_nom,a.nom arme_nom FROM lssd_ppa_sorties s JOIN users u ON u.id=s.agent_id JOIN lssd_ppa_armes a ON a.id=s.arme_id WHERE s.id=? AND s.statut='valide' LIMIT 1");
            $sortie->execute([$sortieId]); $sortie=$sortie->fetch();
            if (!$sortie) throw new Exception("Sortie introuvable ou non validée.");
            if (!$canManage && $sortie['agent_id']!==$uid) throw new Exception("Accès refusé.");
            $recupRow=$db->prepare("SELECT username FROM users WHERE id=? LIMIT 1"); $recupRow->execute([(int)$_POST['recupere_par']]); $recupName=$recupRow->fetchColumn();
            $db->prepare("INSERT INTO lssd_ppa_retours (sortie_id,arme_id,etat_retour,munitions_retour,lieu,recupere_par) VALUES (?,?,?,?,?,?)")
               ->execute([$sortieId,$armeId,trim($_POST['etat_retour']??'Bon état'),(int)($_POST['munitions_retour']??0),trim($_POST['lieu']??''),(int)$_POST['recupere_par']]);
            $db->prepare("UPDATE lssd_ppa_sorties SET statut='rendu' WHERE id=?")->execute([$sortieId]);
            $db->prepare("UPDATE lssd_ppa_armes SET statut='libre',etat=?,stock_munitions=? WHERE id=?")->execute([trim($_POST['etat_retour']??'Bon état'),(int)($_POST['munitions_retour']??0),$armeId]);
            lssd_log($db,$uid,'ppa_retour_arme','ppa-agent',$armeId,$sortie['arme_nom'],"Agent: ".$sortie['agent_nom']." | Récupéré par: $recupName | Lieu: ".trim($_POST['lieu']??''));
            // Webhook Discord
            sendPPAWebhook($webhookUrl,[
                'title'=>'✅ Retour d\'arme PPA',
                'color'=>0x2ecc71,
                'fields'=>[
                    ['name'=>'Arme','value'=>$sortie['arme_nom'],'inline'=>true],
                    ['name'=>'Agent utilisateur','value'=>$sortie['agent_nom'],'inline'=>true],
                    ['name'=>'Récupéré par','value'=>$recupName,'inline'=>true],
                    ['name'=>'État retour','value'=>trim($_POST['etat_retour']??'Bon état'),'inline'=>true],
                    ['name'=>'Munitions restantes','value'=>(string)((int)($_POST['munitions_retour']??0)),'inline'=>true],
                    ['name'=>'Lieu','value'=>trim($_POST['lieu']??'—'),'inline'=>true],
                ],
                'timestamp'=>date('c'),
            ]);
            $msg='Arme rendue — remise en stock.'; $msgType='success';
        } catch(Exception $e){ $msg='Erreur: '.$e->getMessage(); $msgType='error'; }
    }
}

// ── DATA ─────────────────────────────────────────────────────────────────────
$categories = $db->query("SELECT * FROM lssd_ppa_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Armes avec sortie active
$armes = $db->query("
    SELECT a.*,c.nom cat_nom,
           s.id sortie_id, s.agent_id, s.periode_debut, s.periode_fin, s.raison, s.statut sortie_statut,
           s.valide_par, s.valide_at,
           u.username agent_nom,
           uv.username valide_par_nom
    FROM lssd_ppa_armes a
    JOIN lssd_ppa_categories c ON c.id=a.category_id
    LEFT JOIN lssd_ppa_sorties s ON s.arme_id=a.id AND s.statut IN('en_attente','valide')
    LEFT JOIN users u ON u.id=s.agent_id
    LEFT JOIN users uv ON uv.id=s.valide_par
    ORDER BY c.id ASC, a.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Agents LSSD
$agents = $db->query("SELECT id,username,faction_rank FROM users WHERE faction='LSSD' AND is_active=1 ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

// Grouper les armes par catégorie
$armesByCategory = [];
foreach ($armes as $a) $armesByCategory[$a['category_id']][] = $a;

// Stats
$stats = ['total'=>0,'libre'=>0,'en_attente'=>0,'en_service'=>0];
foreach ($armes as $a) { $stats['total']++; $stats[$a['statut']] = ($stats[$a['statut']]??0)+1; }

include __DIR__ . '/includes/header.php';
?>
<style>
/* ── PPA Page ── */
.ppa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.1rem}
.ppa-cat-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem;flex-wrap:wrap;gap:.5rem}
.ppa-cat-title{font-size:1rem;font-weight:700;color:var(--txt);display:flex;align-items:center;gap:.5rem}
.ppa-cat-section{margin-bottom:2rem}

/* Arme card */
.arme-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.1rem;transition:all .2s;position:relative;overflow:hidden}
.arme-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:2px 0 0 2px}
.arme-card.libre::before{background:#22c55e}
.arme-card.en_attente::before{background:#94a3b8}
.arme-card.en_service::before{background:#ef4444}
.arme-card.libre{border-left-color:rgba(34,197,94,.3)}
.arme-card.en_attente{border-left-color:rgba(148,163,184,.3);background:rgba(148,163,184,.03)}
.arme-card.en_service{border-left-color:rgba(239,68,68,.3);background:rgba(239,68,68,.03)}

.arme-status-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.arme-status-dot.libre{background:#22c55e;box-shadow:0 0 6px rgba(34,197,94,.5)}
.arme-status-dot.en_attente{background:#94a3b8}
.arme-status-dot.en_service{background:#ef4444;box-shadow:0 0 6px rgba(239,68,68,.5)}

.arme-nom{font-weight:700;font-size:.9rem;color:var(--txt)}
.arme-serie{font-size:.7rem;color:var(--txt-m);font-family:'IBM Plex Mono',monospace}
.arme-meta{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.5rem;font-size:.7rem;color:var(--txt-s)}
.arme-meta span{background:var(--bg-ov);padding:.15rem .45rem;border-radius:5px}
.arme-info-box{margin-top:.7rem;padding:.6rem .8rem;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);border-radius:8px;font-size:.72rem;color:var(--txt-s)}
.arme-info-box.pending{background:rgba(148,163,184,.06);border-color:rgba(148,163,184,.2)}
.arme-actions{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.8rem;padding-top:.7rem;border-top:1px solid var(--bdr)}

/* Status badge */
.ppa-badge{display:inline-flex;align-items:center;gap:.28rem;padding:.15rem .55rem;border-radius:20px;font-size:.62rem;font-weight:700;letter-spacing:.04em}
.ppa-badge.libre{background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.25)}
.ppa-badge.en_attente{background:rgba(148,163,184,.1);color:#94a3b8;border:1px solid rgba(148,163,184,.25)}
.ppa-badge.en_service{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.25)}

/* Modal overlay déjà défini via header */
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<!-- KPIs -->
<div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem">
    <div class="kpi"><div class="kpi-lbl">Total armes</div><div class="kpi-val blue"><?php echo $stats['total'];?></div></div>
    <div class="kpi"><div class="kpi-lbl"><span style="color:#4ade80">●</span> Libres</div><div class="kpi-val green"><?php echo $stats['libre']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl"><span style="color:#94a3b8">●</span> En attente</div><div class="kpi-val" style="color:#94a3b8"><?php echo $stats['en_attente']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl"><span style="color:#fca5a5">●</span> En service</div><div class="kpi-val red"><?php echo $stats['en_service']??0;?></div></div>
</div>

<!-- Header actions -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem">
    <div>
        <h2 style="font-size:1.15rem;font-weight:800;color:var(--txt);display:flex;align-items:center;gap:.5rem">
            <i class="fas fa-gun" style="color:var(--blue)"></i> Gestion des armes PPA
        </h2>
        <p style="font-size:.75rem;color:var(--txt-m);margin-top:.18rem">
            <?php echo count($categories);?> catégorie(s) · <?php echo $stats['total'];?> arme(s)
            <?php if($myGrade):?> · <span style="color:var(--blue-xl)"><?php echo htmlspecialchars($myGrade);?></span><?php endif;?>
        </p>
    </div>
    <?php if($canManage):?>
    <div style="display:flex;gap:.5rem">
        <button onclick="LSSD.openModal('addCatModal')" class="btn btn-secondary btn-sm"><i class="fas fa-folder-plus"></i> Catégorie</button>
        <button onclick="LSSD.openModal('addArmeModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Ajouter une arme</button>
    </div>
    <?php endif;?>
</div>

<!-- Catégories + Armes -->
<?php foreach ($categories as $cat):
    $catArmes = $armesByCategory[$cat['id']] ?? [];
?>
<div class="ppa-cat-section">
    <div class="ppa-cat-hd">
        <div class="ppa-cat-title">
            <i class="fas fa-box-open" style="color:var(--blue-l);font-size:.85rem"></i>
            <?php echo htmlspecialchars($cat['nom']);?>
            <span style="font-size:.7rem;color:var(--txt-m);font-weight:400">(<?php echo count($catArmes);?> arme<?php echo count($catArmes)>1?'s':'';?>)</span>
        </div>
    </div>

    <?php if(empty($catArmes)):?>
    <div style="padding:1.5rem;text-align:center;border:1px dashed var(--bdr);border-radius:10px;color:var(--txt-m);font-size:.82rem">
        <i class="fas fa-gun" style="opacity:.2;display:block;font-size:1.5rem;margin-bottom:.4rem"></i>
        Aucune arme dans cette catégorie.
        <?php if($canManage):?><br><button onclick="openAddArme(<?php echo $cat['id'];?>,'<?php echo addslashes($cat['nom']);?>')" class="btn btn-ghost btn-sm" style="margin-top:.5rem"><i class="fas fa-plus"></i> Ajouter</button><?php endif;?>
    </div>
    <?php else:?>
    <div class="ppa-grid">
    <?php foreach($catArmes as $a):
        $st = $a['statut'];
        $stLabels = ['libre'=>'Libre','en_attente'=>'En attente de validation','en_service'=>'En service'];
        $stIcons  = ['libre'=>'fa-check','en_attente'=>'fa-clock','en_service'=>'fa-person-rifle'];
    ?>
    <div class="arme-card <?php echo $st;?>">
        <!-- Header carte -->
        <div style="display:flex;align-items:flex-start;gap:.75rem">
            <div class="arme-status-dot <?php echo $st;?>" style="margin-top:.3rem;flex-shrink:0"></div>
            <div style="flex:1;min-width:0">
                <div class="arme-nom"><?php echo htmlspecialchars($a['nom']);?></div>
                <?php if($a['numero_serie']):?><div class="arme-serie">N° <?php echo htmlspecialchars($a['numero_serie']);?></div><?php endif;?>
                <div style="margin-top:.35rem">
                    <span class="ppa-badge <?php echo $st;?>">
                        <i class="fas <?php echo $stIcons[$st]??'fa-circle';?>" style="font-size:.5rem"></i>
                        <?php echo $stLabels[$st]??$st;?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Méta -->
        <div class="arme-meta">
            <span><i class="fas fa-shield-halved" style="color:var(--blue-l)"></i> <?php echo htmlspecialchars($a['etat']??'—');?></span>
            <span><i class="fas fa-circle-dot" style="color:var(--yellow)"></i> <?php echo (int)$a['stock_munitions'];?> mun.</span>
        </div>

        <!-- Info sortie active -->
        <?php if($st==='en_service' && $a['agent_nom']):
            $since = $a['valide_at'] ? round((time()-strtotime($a['valide_at']))/60) : '?';
        ?>
        <div class="arme-info-box">
            <div style="font-weight:700;color:#fca5a5;margin-bottom:.3rem"><i class="fas fa-person-rifle"></i> En service</div>
            <div>Agent : <strong style="color:var(--txt)"><?php echo htmlspecialchars($a['agent_nom']);?></strong></div>
            <?php if($a['valide_par_nom']):?><div>Validé par : <?php echo htmlspecialchars($a['valide_par_nom']);?></div><?php endif;?>
            <div>Depuis : <?php echo $since; ?> min</div>
            <?php if($a['periode_fin']):?><div>Retour prévu : <?php echo date('d/m/Y H:i',strtotime($a['periode_fin']));?></div><?php endif;?>
        </div>
        <?php elseif($st==='en_attente' && $a['agent_nom']):?>
        <div class="arme-info-box pending">
            <div style="font-weight:700;color:#94a3b8;margin-bottom:.3rem"><i class="fas fa-clock"></i> Demande transmise</div>
            <div>Agent demandeur : <strong style="color:var(--txt)"><?php echo htmlspecialchars($a['agent_nom']);?></strong></div>
            <?php if($a['raison']):?><div>Raison : <?php echo htmlspecialchars($a['raison']);?></div><?php endif;?>
        </div>
        <?php endif;?>

        <!-- Actions -->
        <div class="arme-actions">
            <?php if($st==='libre'):?>
                <button onclick="openSortieModal(<?php echo $a['id'];?>,'<?php echo addslashes($a['nom']);?>')" class="btn btn-secondary btn-xs"><i class="fas fa-arrow-up-from-bracket"></i> Sortie</button>
            <?php elseif($st==='en_attente' && $canManage):?>
                <form method="POST" style="margin:0"><input type="hidden" name="action" value="valider_sortie"><input type="hidden" name="sortie_id" value="<?php echo $a['sortie_id'];?>"><button type="submit" class="btn btn-primary btn-xs"><i class="fas fa-check"></i> Valider</button></form>
                <form method="POST" style="margin:0"><input type="hidden" name="action" value="refuser_sortie"><input type="hidden" name="sortie_id" value="<?php echo $a['sortie_id'];?>"><button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('Refuser cette demande ?')"><i class="fas fa-times"></i> Refuser</button></form>
            <?php elseif($st==='en_service' && ($canManage || $a['agent_id']==$uid)):?>
                <button onclick="openRetourModal(<?php echo $a['id'];?>,<?php echo $a['sortie_id'];?>,'<?php echo addslashes($a['nom']);?>')" class="btn btn-secondary btn-xs"><i class="fas fa-rotate-left"></i> Rendre l'arme</button>
            <?php endif;?>
        </div>
    </div>
    <?php endforeach;?>
    </div>
    <?php endif;?>
</div>
<?php endforeach;?>

<?php if(empty($categories)):?>
<div class="card" style="text-align:center;padding:3rem;color:var(--txt-m)">
    <i class="fas fa-gun" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.6rem"></i>
    Aucune catégorie. <?php if($canManage):?><button onclick="LSSD.openModal('addCatModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Créer la première</button><?php endif;?>
</div>
<?php endif;?>

<!-- ══ MODALS ══════════════════════════════════════════════════════════ -->

<!-- Modal : Ajouter catégorie -->
<?php if($canManage):?>
<div id="addCatModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-folder-plus"></i> Nouvelle catégorie</h2><button class="modal-cl" onclick="LSSD.closeModal('addCatModal')"><i class="fas fa-xmark"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_category">
    <div class="form-group"><label class="form-label">Nom de la catégorie *</label>
        <input type="text" name="nom" class="form-input" required placeholder="Ex: PPA VI...">
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('addCatModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer</button></div>
</form></div></div>

<!-- Modal : Ajouter arme -->
<div id="addArmeModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-gun"></i> Ajouter une arme</h2><button class="modal-cl" onclick="LSSD.closeModal('addArmeModal')"><i class="fas fa-xmark"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_arme">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Catégorie *</label>
            <select name="category_id" id="addArme_cat" class="form-select" required>
                <?php foreach($categories as $c):?><option value="<?php echo $c['id'];?>"><?php echo htmlspecialchars($c['nom']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Nom de l'arme *</label>
            <input type="text" name="nom" class="form-input" required placeholder="Ex: Shotgun PPA">
        </div>
        <div class="form-group"><label class="form-label">Numéro de série</label>
            <input type="text" name="numero_serie" class="form-input" placeholder="PPA-2026-001">
        </div>
        <div class="form-group"><label class="form-label">État</label>
            <select name="etat" class="form-select">
                <option>Bon état</option><option>Usé</option><option>Endommagé</option><option>Neuf</option>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Stock munitions</label>
            <input type="number" name="stock_munitions" class="form-input" min="0" value="0">
        </div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('addArmeModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ajouter</button></div>
</form></div></div>
<?php endif;?>

<!-- Modal : Demande de sortie -->
<div id="sortieModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-arrow-up-from-bracket"></i> Demande de sortie</h2><button class="modal-cl" onclick="LSSD.closeModal('sortieModal')"><i class="fas fa-xmark"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="demande_sortie">
    <input type="hidden" name="arme_id" id="sortie_arme_id">
    <div style="font-size:.88rem;font-weight:700;color:var(--txt);margin-bottom:.9rem;padding:.5rem .75rem;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:8px;display:flex;align-items:center;gap:.5rem">
        <i class="fas fa-gun" style="color:var(--blue-l)"></i><span id="sortie_arme_nom">—</span>
    </div>
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Agent demandeur *</label>
            <select name="agent_id" class="form-select" required>
                <?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>" <?php echo $a['id']==$uid?'selected':'';?>><?php echo htmlspecialchars($a['username']);?> (<?php echo htmlspecialchars($a['faction_rank']??'');?>)</option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Début *</label>
            <input type="datetime-local" name="periode_debut" class="form-input" value="<?php echo date('Y-m-d\TH:i');?>" required>
        </div>
        <div class="form-group"><label class="form-label">Fin prévue</label>
            <input type="datetime-local" name="periode_fin" class="form-input">
        </div>
        <div class="form-group" style="grid-column:1/-1"><label class="form-label">Raison *</label>
            <textarea name="raison" class="form-textarea" rows="2" required placeholder="Opération spéciale, patrouille..."></textarea>
        </div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('sortieModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Transmettre la demande</button></div>
</form></div></div>

<!-- Modal : Retour d'arme -->
<div id="retourModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-rotate-left"></i> Retour d'arme</h2><button class="modal-cl" onclick="LSSD.closeModal('retourModal')"><i class="fas fa-xmark"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="retour_arme">
    <input type="hidden" name="arme_id"   id="retour_arme_id">
    <input type="hidden" name="sortie_id" id="retour_sortie_id">
    <div style="font-size:.88rem;font-weight:700;color:var(--txt);margin-bottom:.9rem;padding:.5rem .75rem;background:rgba(34,197,94,.06);border:1px solid rgba(34,197,94,.2);border-radius:8px;display:flex;align-items:center;gap:.5rem">
        <i class="fas fa-gun" style="color:#4ade80"></i><span id="retour_arme_nom">—</span>
    </div>
    <div class="form-grid">
        <div class="form-group"><label class="form-label">État de retour *</label>
            <select name="etat_retour" class="form-select" required>
                <option>Bon état</option><option>Usé</option><option>Endommagé</option><option>Neuf</option>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Munitions restantes</label>
            <input type="number" name="munitions_retour" class="form-input" min="0" value="0">
        </div>
        <div class="form-group"><label class="form-label">Lieu de récupération *</label>
            <input type="text" name="lieu" class="form-input" required placeholder="Commissariat central...">
        </div>
        <div class="form-group"><label class="form-label">Agent récupérateur *</label>
            <select name="recupere_par" class="form-select" required>
                <?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>" <?php echo $a['id']==$uid?'selected':'';?>><?php echo htmlspecialchars($a['username']);?></option><?php endforeach;?>
            </select>
        </div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('retourModal')">Annuler</button><button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#22c55e,#15803d)"><i class="fas fa-check"></i> Confirmer le retour</button></div>
</form></div></div>

<script>
function openSortieModal(id, nom) {
    document.getElementById('sortie_arme_id').value = id;
    document.getElementById('sortie_arme_nom').textContent = nom;
    LSSD.openModal('sortieModal');
}
function openRetourModal(armeId, sortieId, nom) {
    document.getElementById('retour_arme_id').value   = armeId;
    document.getElementById('retour_sortie_id').value = sortieId;
    document.getElementById('retour_arme_nom').textContent = nom;
    LSSD.openModal('retourModal');
}
function openAddArme(catId, catNom) {
    document.getElementById('addArme_cat').value = catId;
    LSSD.openModal('addArmeModal');
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>