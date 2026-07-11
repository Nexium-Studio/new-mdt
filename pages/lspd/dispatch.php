<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
mdt_require_product('addon_dispatch', 'Dispatch');

if (!in_array($_SESSION['user_faction'], ['LSPD','LSSD','DOJ']) &&
    !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db      = Database::getInstance()->getConnection();
$author  = $_SESSION['user_name'] ?? 'Officier';
$isAdmin = hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911',
                    'Lieutenant I','Lieutenant II','Captain','Commander','Assistant Chief','Chief of Police']);

define('UPLOAD_DIR', __DIR__ . '/../../public/uploads/dispatch/');
define('UPLOAD_URL', SITE_URL . '/public/uploads/dispatch/');

$CODE_TEN = [
    '10-06'=>'gray','10-07'=>'gray','10-08'=>'gray','10-10'=>'gray',
    '10-23'=>'gray','10-98'=>'gray','10-03'=>'gray',
    '10-38'=>'green','10-50'=>'green','10-51'=>'green',
    '10-52'=>'green','10-56'=>'green','10-57'=>'green',
    '10-35'=>'red','10-31'=>'red','10-91'=>'red',
    '10-40'=>'orange','10-59'=>'orange','10-60'=>'orange','10-74'=>'orange','10-75'=>'orange',
    '10-19'=>'blue','10-14'=>'blue','10-15'=>'blue',
];
$OTHER_TAGS = [
    'En Pause'=>'gray','En Formation'=>'gray','En Déplacement'=>'green',
    'En Attente'=>'purple','En Meeting'=>'yellow','Code 5'=>'red',
];
/* Charger depuis dispatch_config si disponible */
$DISPATCH_THEME = ['bg_type'=>'gradient','bg_color'=>'#060f1d','bg_image'=>'','bg_overlay'=>'0.55','accent'=>'#00e5ff','board_title'=>'DISPATCH BOARD','col_opacity'=>'0.88','card_radius'=>'12','topbar_opacity'=>'0.97','col_blur'=>'8'];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS dispatch_config(config_key VARCHAR(64) PRIMARY KEY, config_value TEXT)");
    $cfg = $db->query("SELECT config_key, config_value FROM dispatch_config WHERE config_key IN ('code_ten','other_tags','dispatch_theme')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($cfg['code_ten']))       $CODE_TEN       = json_decode($cfg['code_ten'],       true) ?: $CODE_TEN;
    if (!empty($cfg['other_tags']))     $OTHER_TAGS     = json_decode($cfg['other_tags'],     true) ?: $OTHER_TAGS;
    if (!empty($cfg['dispatch_theme'])) $DISPATCH_THEME = array_merge($DISPATCH_THEME, json_decode($cfg['dispatch_theme'], true) ?: []);
} catch(Exception $e) { /* table pas encore créée, on garde les defaults */ }

/* ─── Auto-création tables Plaques + Checklists Globales (idempotent) ─── */
try {
    $db->exec("CREATE TABLE IF NOT EXISTS dispatch_vehicle_plates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        plate VARCHAR(16) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_vehicle_plate (vehicle_id, plate),
        KEY idx_plate (plate)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS dispatch_global_checklists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(128) NOT NULL,
        position INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS dispatch_global_checklist_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        checklist_id INT NOT NULL,
        text VARCHAR(255) NOT NULL,
        position INT DEFAULT 0,
        KEY idx_checklist (checklist_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS dispatch_card_global_checks (
        card_id INT NOT NULL,
        item_id INT NOT NULL,
        is_done TINYINT(1) NOT NULL DEFAULT 1,
        done_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (card_id, item_id),
        KEY idx_card (card_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* Colonnes additionnelles sur dispatch_cards (best-effort, ignore si déjà existantes) */
    $cols = $db->query("SHOW COLUMNS FROM dispatch_cards")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('vehicle_plate', $cols, true))         $db->exec("ALTER TABLE dispatch_cards ADD COLUMN vehicle_plate VARCHAR(16) NULL DEFAULT NULL");
    if (!in_array('use_global_checklists', $cols, true)) $db->exec("ALTER TABLE dispatch_cards ADD COLUMN use_global_checklists TINYINT(1) NOT NULL DEFAULT 0");
    if (!in_array('assigned_agents', $cols, true))       $db->exec("ALTER TABLE dispatch_cards ADD COLUMN assigned_agents TEXT NULL DEFAULT NULL");
    /* Journalisation des actions + archive des cartes supprimées */
    $db->exec("CREATE TABLE IF NOT EXISTS dispatch_card_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        card_id INT NOT NULL,
        user_id INT NULL,
        action VARCHAR(64) NOT NULL,
        detail TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_card (card_id),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS dispatch_cards_archive (
        id INT PRIMARY KEY,
        list_name VARCHAR(128) NULL,
        title VARCHAR(255) NULL,
        description TEXT NULL,
        priority VARCHAR(32) NULL,
        location VARCHAR(255) NULL,
        assigned_to TEXT NULL,
        grade_tags TEXT NULL,
        patrol_vehicle VARCHAR(128) NULL,
        vehicle_plate VARCHAR(16) NULL,
        code_ten_tags TEXT NULL,
        other_tags TEXT NULL,
        card_color VARCHAR(20) NULL,
        cover_image VARCHAR(255) NULL,
        created_at TIMESTAMP NULL,
        created_by INT NULL,
        deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        deleted_by INT NULL,
        snapshot TEXT NULL,
        KEY idx_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) { /* ignore */ }

/** Journalise une action sur une carte dispatch. */
function dispatch_log(PDO $db, int $cardId, string $action, ?string $detail = null): void {
    try {
        $db->prepare("INSERT INTO dispatch_card_logs (card_id,user_id,action,detail) VALUES (?,?,?,?)")
           ->execute([$cardId, $_SESSION['user_id'] ?? null, $action, $detail]);
    } catch (Throwable $e) { /* ignore */ }
}

/* ─── AJAX ─────────────────────────────────────────────────── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $act = $_POST['action'] ?? $_GET['action'] ?? '';
    try {
        switch ($act) {
            /* LISTS */
            case 'add_list':
                $pos = $db->query("SELECT COALESCE(MAX(position),0)+1 FROM dispatch_lists")->fetchColumn();
                $s=$db->prepare("INSERT INTO dispatch_lists(name,position,color)VALUES(?,?,?)");
                $s->execute([trim($_POST['name']),$pos,$_POST['color']??'#00e5ff']);
                echo json_encode(['success'=>true,'id'=>$db->lastInsertId()]);break;
            case 'update_list':
                $db->prepare("UPDATE dispatch_lists SET name=?,color=? WHERE id=?")
                   ->execute([trim($_POST['name']),$_POST['color'],intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            case 'delete_list':
                $db->prepare("DELETE FROM dispatch_lists WHERE id=?")->execute([intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            case 'reorder_lists':
                $ids=json_decode($_POST['ids'],true);
                $s=$db->prepare("UPDATE dispatch_lists SET position=? WHERE id=?");
                foreach($ids as $p=>$id)$s->execute([$p,$id]);
                echo json_encode(['success'=>true]);break;
            /* CARDS */
            case 'add_card':
                $lid=intval($_POST['list_id']);
                $pos=$db->prepare("SELECT COALESCE(MAX(position),0)+1 FROM dispatch_cards WHERE list_id=?");
                $pos->execute([$lid]);$p=$pos->fetchColumn();
                $s=$db->prepare("INSERT INTO dispatch_cards(list_id,title,priority,position,created_by)VALUES(?,?,'normale',?,?)");
                $s->execute([$lid,trim($_POST['title']),$p,$_SESSION['user_id']??null]);
                $newCardId=(int)$db->lastInsertId();
                dispatch_log($db,$newCardId,'create','Carte créée : '.trim($_POST['title']));
                echo json_encode(['success'=>true,'id'=>$newCardId]);break;
            case 'update_card':
                $cardId = intval($_POST['id']);
                $vehicleName = trim($_POST['patrol_vehicle']??'')?:null;
                $plate       = trim($_POST['vehicle_plate']??'')?:null;
                /* Vérification d'unicité de la plaque sur le board (sauf carte courante) */
                if ($plate !== null) {
                    $chk = $db->prepare("SELECT id FROM dispatch_cards WHERE vehicle_plate=? AND id<>? LIMIT 1");
                    $chk->execute([$plate, $cardId]);
                    if ($chk->fetchColumn()) { echo json_encode(['success'=>false,'error'=>'Plaque déjà utilisée par une autre carte']); break; }
                }
                /* Snapshot avant modification pour journaliser les changements */
                $oldRow=null; try{$o=$db->prepare("SELECT * FROM dispatch_cards WHERE id=?");$o->execute([$cardId]);$oldRow=$o->fetch(PDO::FETCH_ASSOC);}catch(Exception $e){}
                /* Agents assignés (structurés) + grade-tags AUTO depuis leurs divisions */
                $agentsArr=json_decode($_POST['assigned_agents']??'', true); if(!is_array($agentsArr)) $agentsArr=[];
                $assignedAgentsVal = $agentsArr ? json_encode(array_values($agentsArr)) : null;
                if ($agentsArr) {
                    $autoG=[]; foreach($agentsArr as $a){ foreach(explode(',', (string)($a['divisions']??'')) as $d){ $d=trim($d); if($d!=='') $autoG[$d]=true; } }
                    $gradeTagsVal = $autoG ? json_encode(array_keys($autoG)) : null;
                    // assigned_to (texte lisible rétro-compatible)
                    $assignedToVal = implode(', ', array_map(fn($a)=>trim(($a['name']??'').($a['matricule']?' #'.$a['matricule']:'')), $agentsArr)) ?: null;
                } else {
                    $gradeTagsVal = !empty($_POST['grade_tags'])?$_POST['grade_tags']:null;
                    $assignedToVal = trim($_POST['assigned_to']??'')?:null;
                }
                $s=$db->prepare("UPDATE dispatch_cards SET title=?,description=?,priority=?,due_date=?,assigned_to=?,assigned_agents=?,location=?,grade_tags=?,patrol_vehicle=?,vehicle_plate=?,code_ten_tags=?,other_tags=?,card_color=? WHERE id=?");
                $s->execute([
                    trim($_POST['title']),$_POST['description']??null,
                    $_POST['priority']??'normale',
                    !empty($_POST['due_date'])?$_POST['due_date']:null,
                    $assignedToVal,$assignedAgentsVal,trim($_POST['location']??'')?:null,
                    $gradeTagsVal,
                    $vehicleName,
                    $plate,
                    !empty($_POST['code_ten_tags'])?$_POST['code_ten_tags']:null,
                    !empty($_POST['other_tags'])?$_POST['other_tags']:null,
                    !empty($_POST['card_color'])?$_POST['card_color']:null,
                    $cardId
                ]);
                /* Journalisation granulaire des changements */
                if ($oldRow) {
                    $newV=['title'=>trim($_POST['title']),'description'=>$_POST['description']??null,'priority'=>$_POST['priority']??'normale','assigned_to'=>$assignedToVal,'location'=>trim($_POST['location']??'')?:null,'patrol_vehicle'=>$vehicleName,'vehicle_plate'=>$plate,'code_ten_tags'=>!empty($_POST['code_ten_tags'])?$_POST['code_ten_tags']:null,'other_tags'=>!empty($_POST['other_tags'])?$_POST['other_tags']:null];
                    $lbl=['title'=>'le titre','description'=>'la description','priority'=>'la priorité','assigned_to'=>'les agents assignés','location'=>'le lieu','patrol_vehicle'=>'le véhicule','vehicle_plate'=>'la plaque','code_ten_tags'=>'le Code Ten','other_tags'=>'les tags statut'];
                    foreach ($newV as $k=>$v) {
                        $ov=(string)($oldRow[$k]??''); if ($ov===(string)($v??'')) continue;
                        $act = $k==='assigned_to'?'agents':($k==='code_ten_tags'?'code_ten':($k==='other_tags'?'tags':($k==='priority'?'status':'update_'.$k)));
                        dispatch_log($db,$cardId,$act,'a modifié '.$lbl[$k].($v!==null&&$v!==''?(' → '.mb_substr((string)$v,0,120)):''));
                    }
                }
                echo json_encode(['success'=>true]);break;
            case 'delete_card':
                $id=intval($_POST['id']);
                /* Archive (soft-delete) : la carte est conservée dans dispatch_cards_archive */
                try {
                    $cr=$db->prepare("SELECT c.*, l.name AS list_name FROM dispatch_cards c LEFT JOIN dispatch_lists l ON l.id=c.list_id WHERE c.id=?");
                    $cr->execute([$id]); $cardRow=$cr->fetch(PDO::FETCH_ASSOC);
                    if ($cardRow) {
                        $ins=$db->prepare("INSERT INTO dispatch_cards_archive
                            (id,list_name,title,description,priority,location,assigned_to,grade_tags,patrol_vehicle,vehicle_plate,code_ten_tags,other_tags,card_color,cover_image,created_at,created_by,deleted_by,snapshot)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE deleted_at=CURRENT_TIMESTAMP, deleted_by=VALUES(deleted_by)");
                        $ins->execute([
                            $id,$cardRow['list_name']??null,$cardRow['title']??null,$cardRow['description']??null,$cardRow['priority']??null,
                            $cardRow['location']??null,$cardRow['assigned_to']??null,$cardRow['grade_tags']??null,$cardRow['patrol_vehicle']??null,
                            $cardRow['vehicle_plate']??null,$cardRow['code_ten_tags']??null,$cardRow['other_tags']??null,$cardRow['card_color']??null,
                            $cardRow['cover_image']??null,$cardRow['created_at']??null,$cardRow['created_by']??null,
                            $_SESSION['user_id']??null, json_encode($cardRow)
                        ]);
                    }
                } catch(Exception $e) { /* archive best-effort */ }
                dispatch_log($db,$id,'delete','a supprimé la carte'.(isset($cardRow['title'])?' « '.$cardRow['title'].' »':''));
                /* On NE supprime PAS le fichier de couverture (conservé pour l'archive) */
                $db->prepare("DELETE FROM dispatch_cards WHERE id=?")->execute([$id]);
                echo json_encode(['success'=>true]);break;
            case 'reorder_cards':
                $ids=json_decode($_POST['ids'],true);$lid=intval($_POST['list_id']);
                $s=$db->prepare("UPDATE dispatch_cards SET list_id=?,position=? WHERE id=?");
                foreach($ids as $p=>$id)$s->execute([$lid,$p,$id]);
                echo json_encode(['success'=>true]);break;
            case 'upload_cover':
                if(!isset($_FILES['image'])){echo json_encode(['success'=>false,'error'=>'No file']);break;}
                $f=$_FILES['image'];
                if(!in_array($f['type'],['image/jpeg','image/png','image/gif','image/webp'])){echo json_encode(['success'=>false,'error'=>'Type invalide']);break;}
                if($f['size']>5*1024*1024){echo json_encode(['success'=>false,'error'=>'Max 5 MB']);break;}
                if(!is_dir(UPLOAD_DIR))mkdir(UPLOAD_DIR,0755,true);
                $cid=intval($_POST['card_id']);
                $fn='card_'.$cid.'_'.time().'.'.pathinfo($f['name'],PATHINFO_EXTENSION);
                $old=$db->prepare("SELECT cover_image FROM dispatch_cards WHERE id=?");$old->execute([$cid]);
                if($of=$old->fetchColumn())@unlink(UPLOAD_DIR.$of);
                if(move_uploaded_file($f['tmp_name'],UPLOAD_DIR.$fn)){
                    $db->prepare("UPDATE dispatch_cards SET cover_image=? WHERE id=?")->execute([$fn,$cid]);
                    dispatch_log($db,$cid,'cover_add','a ajouté une image de couverture');
                    echo json_encode(['success'=>true,'url'=>UPLOAD_URL.$fn]);
                }else echo json_encode(['success'=>false,'error'=>'Upload échoué']);
                break;
            case 'set_cover_url':
                /* Définit la couverture depuis une URL externe / CDN LSPD */
                $cid=intval($_POST['card_id']); $url=trim($_POST['url']??'');
                if($url===''||!preg_match('#^https?://#i',$url)){echo json_encode(['success'=>false,'error'=>'URL invalide']);break;}
                $old=$db->prepare("SELECT cover_image FROM dispatch_cards WHERE id=?");$old->execute([$cid]);
                if($of=$old->fetchColumn()){ if(strpos($of,'http')!==0)@unlink(UPLOAD_DIR.$of); }
                $db->prepare("UPDATE dispatch_cards SET cover_image=? WHERE id=?")->execute([$url,$cid]);
                dispatch_log($db,$cid,'cover_add','a défini une image de couverture depuis le CDN');
                echo json_encode(['success'=>true,'url'=>$url]);break;
            case 'remove_cover':
                $cid=intval($_POST['card_id']);
                $old=$db->prepare("SELECT cover_image FROM dispatch_cards WHERE id=?");$old->execute([$cid]);
                if($of=$old->fetchColumn()){ if(strpos($of,'http')!==0)@unlink(UPLOAD_DIR.$of); }
                $db->prepare("UPDATE dispatch_cards SET cover_image=NULL WHERE id=?")->execute([$cid]);
                dispatch_log($db,$cid,'cover_remove','a supprimé l\'image de couverture');
                echo json_encode(['success'=>true]);break;
            case 'get_card_logs':
                $cid=intval($_POST['card_id']??$_GET['card_id']??0);
                $logs=[];
                try{
                    $s=$db->prepare("SELECT lg.action,lg.detail,lg.created_at,u.username,p.firstname,p.lastname,p.avatar
                                     FROM dispatch_card_logs lg LEFT JOIN users u ON u.id=lg.user_id LEFT JOIN profiles p ON p.id=u.profile_id
                                     WHERE lg.card_id=? ORDER BY lg.created_at DESC LIMIT 100");
                    $s->execute([$cid]);
                    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
                        $logs[]=['action'=>$r['action'],'detail'=>$r['detail'],'at'=>$r['created_at'],
                                 'name'=>function_exists('mdt_rp_display_name')?mdt_rp_display_name($r['firstname'],$r['lastname'],(string)($r['username']??'')):($r['username']??'—'),
                                 'avatar'=>$r['avatar']??''];
                    }
                }catch(Exception $e){}
                echo json_encode(['success'=>true,'logs'=>$logs]);break;
            case 'get_duty_agents':
                /* Officiers LSPD actuellement EN SERVICE (company_shifts ouverts) */
                $agents=[]; $seen=[];
                try{
                    $rows=$db->query("SELECT cs.user_id, u.username, u.faction_rank, p.firstname, p.lastname, p.avatar,
                        (SELECT number FROM academy_matricules WHERE faction_key='LSPD' AND user_id=u.id LIMIT 1) AS matricule,
                        (SELECT GROUP_CONCAT(d.name SEPARATOR ', ') FROM lspd_division_members ldm JOIN lspd_divisions d ON d.id=ldm.division_id WHERE ldm.user_id=u.id) AS divisions
                        FROM company_shifts cs JOIN users u ON u.id=cs.user_id LEFT JOIN profiles p ON p.id=u.profile_id
                        WHERE cs.faction='LSPD' AND cs.shift_end IS NULL ORDER BY u.username")->fetchAll(PDO::FETCH_ASSOC);
                    foreach($rows as $a){ $id=(int)$a['user_id']; if(isset($seen[$id]))continue; $seen[$id]=1;
                        $av=$a['avatar']??''; $avSrc=$av?(preg_match('#^https?://#',$av)?$av:rtrim(SITE_URL,'/').$av):'';
                        $agents[]=['id'=>$id,'name'=>function_exists('mdt_rp_display_name')?mdt_rp_display_name($a['firstname'],$a['lastname'],(string)$a['username']):($a['username']??''),
                                   'grade'=>$a['faction_rank']?:'','matricule'=>$a['matricule']?:'','avatar'=>$avSrc,'divisions'=>$a['divisions']?:''];
                    }
                }catch(Exception $e){}
                $grades=[]; try{ $grades=array_values(array_filter(array_unique(array_map(fn($a)=>$a['grade'],$agents)))); }catch(Exception $e){}
                $divs=[];   try{ foreach($db->query("SELECT name FROM lspd_divisions WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $d) $divs[]=$d; }catch(Exception $e){}
                echo json_encode(['success'=>true,'agents'=>$agents,'grades'=>$grades,'divisions'=>$divs]);break;
            /* LABELS */
            case 'toggle_card_label':
                $cid=intval($_POST['card_id']);$lid=intval($_POST['label_id']);
                $s=$db->prepare("SELECT 1 FROM dispatch_card_labels WHERE card_id=? AND label_id=?");$s->execute([$cid,$lid]);
                if($s->fetchColumn()){
                    $db->prepare("DELETE FROM dispatch_card_labels WHERE card_id=? AND label_id=?")->execute([$cid,$lid]);
                    echo json_encode(['success'=>true,'state'=>'removed']);
                }else{
                    $db->prepare("INSERT INTO dispatch_card_labels(card_id,label_id)VALUES(?,?)")->execute([$cid,$lid]);
                    echo json_encode(['success'=>true,'state'=>'added']);
                }break;
            case 'add_label':
                $s=$db->prepare("INSERT INTO dispatch_labels(name,color)VALUES(?,?)");
                $s->execute([trim($_POST['name']),$_POST['color']]);
                echo json_encode(['success'=>true,'id'=>$db->lastInsertId()]);break;
            case 'delete_label':
                $db->prepare("DELETE FROM dispatch_labels WHERE id=?")->execute([intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            /* CHECKLISTS */
            case 'add_checklist':
                $s=$db->prepare("INSERT INTO dispatch_checklists(card_id,title)VALUES(?,?)");
                $s->execute([intval($_POST['card_id']),trim($_POST['title'])]);
                echo json_encode(['success'=>true,'id'=>$db->lastInsertId()]);break;
            case 'delete_checklist':
                $db->prepare("DELETE FROM dispatch_checklists WHERE id=?")->execute([intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            case 'add_checklist_item':
                $clid=intval($_POST['checklist_id']);
                $pp=$db->prepare("SELECT COALESCE(MAX(position),0)+1 FROM dispatch_checklist_items WHERE checklist_id=?");
                $pp->execute([$clid]);$pos=$pp->fetchColumn();
                $s=$db->prepare("INSERT INTO dispatch_checklist_items(checklist_id,text,position)VALUES(?,?,?)");
                $s->execute([$clid,trim($_POST['text']),$pos]);
                echo json_encode(['success'=>true,'id'=>$db->lastInsertId()]);break;
            case 'toggle_checklist_item':
                $db->prepare("UPDATE dispatch_checklist_items SET is_done=1-is_done WHERE id=?")->execute([intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            case 'delete_checklist_item':
                $db->prepare("DELETE FROM dispatch_checklist_items WHERE id=?")->execute([intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            /* COMMENTS */
            case 'add_comment':
                $s=$db->prepare("INSERT INTO dispatch_comments(card_id,author,content)VALUES(?,?,?)");
                $s->execute([intval($_POST['card_id']),$author,trim($_POST['content'])]);
                $id=$db->lastInsertId();
                echo json_encode(['success'=>true,'id'=>$id,'author'=>$author,'content'=>trim($_POST['content']),'created_at'=>date('Y-m-d H:i:s')]);break;
            case 'delete_comment':
                $db->prepare("DELETE FROM dispatch_comments WHERE id=?")->execute([intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            /* PATROL VEHICLES */
            case 'add_vehicle':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $pos=$db->query("SELECT COALESCE(MAX(position),0)+1 FROM dispatch_patrol_vehicles")->fetchColumn();
                $s=$db->prepare("INSERT INTO dispatch_patrol_vehicles(name,position)VALUES(?,?)");
                $s->execute([trim($_POST['name']),$pos]);
                echo json_encode(['success'=>true,'id'=>$db->lastInsertId()]);break;
            case 'delete_vehicle':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $db->prepare("DELETE FROM dispatch_patrol_vehicles WHERE id=?")->execute([intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            /* GRADES */
            case 'add_grade':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $pos=$db->query("SELECT COALESCE(MAX(position),0)+1 FROM dispatch_grades")->fetchColumn();
                $s=$db->prepare("INSERT INTO dispatch_grades(name,position)VALUES(?,?)");
                $s->execute([trim($_POST['name']),$pos]);
                echo json_encode(['success'=>true,'id'=>$db->lastInsertId()]);break;
            case 'rename_vehicle':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $db->prepare("UPDATE dispatch_patrol_vehicles SET name=? WHERE id=?")->execute([trim($_POST['name']),intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            case 'rename_grade':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $db->prepare("UPDATE dispatch_grades SET name=? WHERE id=?")->execute([trim($_POST['name']),intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            /* CODE TEN (stocké en PHP, géré via fichier config JSON en DB) */
            case 'get_code_ten':
                echo json_encode(['success'=>true,'data'=>$CODE_TEN]);break;
            case 'get_other_tags':
                echo json_encode(['success'=>true,'data'=>$OTHER_TAGS]);break;
            case 'save_code_ten':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $data=json_decode($_POST['data'],true);
                $db->prepare("INSERT INTO dispatch_config(config_key,config_value)VALUES('code_ten',?)ON DUPLICATE KEY UPDATE config_value=?")->execute([json_encode($data),json_encode($data)]);
                echo json_encode(['success'=>true]);break;
            case 'save_other_tags':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $data=json_decode($_POST['data'],true);
                $db->prepare("INSERT INTO dispatch_config(config_key,config_value)VALUES('other_tags',?)ON DUPLICATE KEY UPDATE config_value=?")->execute([json_encode($data),json_encode($data)]);
                echo json_encode(['success'=>true]);break;
            /* ─── PLAQUES VEHICULE ─── */
            case 'get_plates':
                $vid = intval($_POST['vehicle_id'] ?? $_GET['vehicle_id'] ?? 0);
                $self = intval($_POST['card_id'] ?? $_GET['card_id'] ?? 0);
                if($vid<=0){echo json_encode(['success'=>true,'plates'=>[]]);break;}
                $vn = $db->prepare("SELECT name FROM dispatch_patrol_vehicles WHERE id=?");
                $vn->execute([$vid]); $vname = $vn->fetchColumn();
                $st = $db->prepare("SELECT plate FROM dispatch_vehicle_plates WHERE vehicle_id=? ORDER BY plate");
                $st->execute([$vid]);
                $plates = $st->fetchAll(PDO::FETCH_COLUMN);
                /* Plaques utilisées (sur d'autres cartes) */
                $used = [];
                if(!empty($plates) && $vname){
                    $ph = implode(',', array_fill(0, count($plates), '?'));
                    $params = $plates; $params[] = $self;
                    $u = $db->prepare("SELECT vehicle_plate FROM dispatch_cards WHERE vehicle_plate IN ($ph) AND id<>?");
                    $u->execute($params);
                    $used = $u->fetchAll(PDO::FETCH_COLUMN);
                }
                $out = [];
                foreach($plates as $p) $out[] = ['plate'=>$p, 'used'=>in_array($p, $used, true)];
                echo json_encode(['success'=>true,'vehicle'=>$vname,'plates'=>$out]); break;
            case 'add_plate':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $vid = intval($_POST['vehicle_id']); $plate = strtoupper(trim($_POST['plate']));
                if($vid<=0 || $plate==='') {echo json_encode(['success'=>false,'error'=>'Paramètres invalides']);break;}
                if(strlen($plate)>16){echo json_encode(['success'=>false,'error'=>'Plaque trop longue']);break;}
                try {
                    $db->prepare("INSERT INTO dispatch_vehicle_plates(vehicle_id,plate) VALUES(?,?)")->execute([$vid,$plate]);
                    echo json_encode(['success'=>true,'id'=>$db->lastInsertId(),'plate'=>$plate]);
                } catch(Exception $e){
                    if(strpos($e->getMessage(),'Duplicate')!==false) echo json_encode(['success'=>false,'error'=>'Plaque déjà existante pour ce véhicule']);
                    else echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
                }
                break;
            case 'delete_plate':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $db->prepare("DELETE FROM dispatch_vehicle_plates WHERE id=?")->execute([intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            case 'add_plates_bulk':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $vid = intval($_POST['vehicle_id']);
                $raw = (string)($_POST['plates'] ?? '');
                if($vid<=0){echo json_encode(['success'=>false,'error'=>'Véhicule invalide']);break;}
                $arr = preg_split('/[\s,;]+/', strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY);
                $added=0;$skipped=0;$errs=[];
                $ins=$db->prepare("INSERT IGNORE INTO dispatch_vehicle_plates(vehicle_id,plate) VALUES(?,?)");
                foreach($arr as $p){
                    $p=trim($p);
                    if($p==='' || strlen($p)>16){$skipped++;continue;}
                    try{ $ins->execute([$vid,$p]); if($ins->rowCount()>0)$added++;else$skipped++; }
                    catch(Exception $e){$skipped++;$errs[]=$e->getMessage();}
                }
                echo json_encode(['success'=>true,'added'=>$added,'skipped'=>$skipped]);break;
            /* ─── CHECKLISTS GLOBALES ─── */
            case 'get_global_checklists':
                $rows = $db->query("SELECT * FROM dispatch_global_checklists ORDER BY position, id")->fetchAll(PDO::FETCH_ASSOC);
                foreach($rows as &$r){
                    $is = $db->prepare("SELECT * FROM dispatch_global_checklist_items WHERE checklist_id=? ORDER BY position, id");
                    $is->execute([$r['id']]);
                    $r['items'] = $is->fetchAll(PDO::FETCH_ASSOC);
                }
                echo json_encode(['success'=>true,'checklists'=>$rows]); break;
            case 'add_global_checklist':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $title = trim($_POST['title'] ?? ''); if($title===''){echo json_encode(['success'=>false,'error'=>'Titre requis']);break;}
                $pos = $db->query("SELECT COALESCE(MAX(position),0)+1 FROM dispatch_global_checklists")->fetchColumn();
                $db->prepare("INSERT INTO dispatch_global_checklists(title,position) VALUES(?,?)")->execute([$title,$pos]);
                echo json_encode(['success'=>true,'id'=>$db->lastInsertId(),'title'=>$title]);break;
            case 'update_global_checklist':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $db->prepare("UPDATE dispatch_global_checklists SET title=? WHERE id=?")->execute([trim($_POST['title']),intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            case 'delete_global_checklist':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $db->prepare("DELETE FROM dispatch_global_checklists WHERE id=?")->execute([intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            case 'add_global_item':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $clid = intval($_POST['checklist_id']); $text = trim($_POST['text'] ?? '');
                if($clid<=0||$text===''){echo json_encode(['success'=>false,'error'=>'Paramètres invalides']);break;}
                $pos = $db->prepare("SELECT COALESCE(MAX(position),0)+1 FROM dispatch_global_checklist_items WHERE checklist_id=?");
                $pos->execute([$clid]); $p = $pos->fetchColumn();
                $db->prepare("INSERT INTO dispatch_global_checklist_items(checklist_id,text,position) VALUES(?,?,?)")->execute([$clid,$text,$p]);
                echo json_encode(['success'=>true,'id'=>$db->lastInsertId(),'text'=>$text]);break;
            case 'delete_global_item':
                if(!$isAdmin){echo json_encode(['success'=>false,'error'=>'Accès refusé']);break;}
                $db->prepare("DELETE FROM dispatch_global_checklist_items WHERE id=?")->execute([intval($_POST['id'])]);
                echo json_encode(['success'=>true]);break;
            case 'toggle_card_use_global':
                $cid = intval($_POST['card_id']);
                $v   = !empty($_POST['active']) && $_POST['active']!=='0' ? 1 : 0;
                $db->prepare("UPDATE dispatch_cards SET use_global_checklists=? WHERE id=?")->execute([$v,$cid]);
                echo json_encode(['success'=>true,'active'=>$v]);break;
            case 'toggle_card_global_check':
                $cid = intval($_POST['card_id']); $itemId = intval($_POST['item_id']);
                $st = $db->prepare("SELECT 1 FROM dispatch_card_global_checks WHERE card_id=? AND item_id=?");
                $st->execute([$cid,$itemId]);
                if($st->fetchColumn()){
                    $db->prepare("DELETE FROM dispatch_card_global_checks WHERE card_id=? AND item_id=?")->execute([$cid,$itemId]);
                    echo json_encode(['success'=>true,'state'=>'unchecked']);
                } else {
                    $db->prepare("INSERT INTO dispatch_card_global_checks(card_id,item_id) VALUES(?,?)")->execute([$cid,$itemId]);
                    echo json_encode(['success'=>true,'state'=>'checked']);
                }
                break;
            /* GET CARD */
            case 'get_card':
                $id=intval($_GET['id']);
                $s=$db->prepare("SELECT * FROM dispatch_cards WHERE id=?");$s->execute([$id]);
                $card=$s->fetch(PDO::FETCH_ASSOC);
                if(!$card){echo json_encode(['success'=>false]);break;}
                $s=$db->prepare("SELECT l.* FROM dispatch_labels l JOIN dispatch_card_labels cl ON l.id=cl.label_id WHERE cl.card_id=?");
                $s->execute([$id]);$card['labels']=$s->fetchAll(PDO::FETCH_ASSOC);
                $card['all_labels']=$db->query("SELECT * FROM dispatch_labels ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                $s=$db->prepare("SELECT * FROM dispatch_checklists WHERE card_id=? ORDER BY created_at");
                $s->execute([$id]);$cls=$s->fetchAll(PDO::FETCH_ASSOC);
                foreach($cls as &$cl){
                    $s2=$db->prepare("SELECT * FROM dispatch_checklist_items WHERE checklist_id=? ORDER BY position");
                    $s2->execute([$cl['id']]);$cl['items']=$s2->fetchAll(PDO::FETCH_ASSOC);
                }
                $card['checklists']=$cls;
                $s=$db->prepare("SELECT * FROM dispatch_comments WHERE card_id=? ORDER BY created_at");
                $s->execute([$id]);
                $card['comments']=$s->fetchAll(PDO::FETCH_ASSOC);
                $card['cover_url']=$card['cover_image']?(preg_match('#^https?://#',$card['cover_image'])?$card['cover_image']:UPLOAD_URL.$card['cover_image']):null;
                $card['grade_tags']=$card['grade_tags']?json_decode($card['grade_tags'],true):[];
                $card['code_ten_tags']=$card['code_ten_tags']?json_decode($card['code_ten_tags'],true):[];
                $card['other_tags']=$card['other_tags']?json_decode($card['other_tags'],true):[];
                $card['assigned_agents']=$card['assigned_agents']?(json_decode($card['assigned_agents'],true)?:[]):[];
                /* Checklists globales — items cochés */
                $gc = $db->prepare("SELECT item_id FROM dispatch_card_global_checks WHERE card_id=?");
                $gc->execute([$id]);
                $card['global_checks'] = array_map('intval', $gc->fetchAll(PDO::FETCH_COLUMN));
                $card['use_global_checklists'] = (int)($card['use_global_checklists'] ?? 0);
                /* Plaques disponibles si véhicule sélectionné */
                $card['available_plates'] = [];
                if (!empty($card['patrol_vehicle'])) {
                    $vid = $db->prepare("SELECT id FROM dispatch_patrol_vehicles WHERE name=?");
                    $vid->execute([$card['patrol_vehicle']]);
                    $vehicleId = (int)$vid->fetchColumn();
                    if ($vehicleId > 0) {
                        $ps = $db->prepare("SELECT plate FROM dispatch_vehicle_plates WHERE vehicle_id=? ORDER BY plate");
                        $ps->execute([$vehicleId]);
                        $allP = $ps->fetchAll(PDO::FETCH_COLUMN);
                        $usedP = [];
                        if (!empty($allP)) {
                            $ph = implode(',', array_fill(0, count($allP), '?'));
                            $params = $allP; $params[] = $id;
                            $u = $db->prepare("SELECT vehicle_plate FROM dispatch_cards WHERE vehicle_plate IN ($ph) AND id<>?");
                            $u->execute($params);
                            $usedP = $u->fetchAll(PDO::FETCH_COLUMN);
                        }
                        foreach ($allP as $p) $card['available_plates'][] = ['plate'=>$p, 'used'=>in_array($p, $usedP, true)];
                    }
                }
                echo json_encode(['success'=>true,'card'=>$card]);break;
            /* TEMPS RÉEL — poll léger */
            case 'poll_board':
                $lists_p = $db->query("SELECT id,name,color,position FROM dispatch_lists ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
                $cards_p = $db->query("
                    SELECT c.id,c.list_id,c.title,c.priority,c.card_color,c.cover_image,
                           c.assigned_to,c.assigned_agents,c.location,c.due_date,c.description,
                           c.grade_tags,c.patrol_vehicle,c.vehicle_plate,c.code_ten_tags,c.other_tags,
                           c.use_global_checklists,c.position,c.updated_at,
                           GROUP_CONCAT(l.id,'|',l.name,'|',l.color SEPARATOR ';;') as labels_raw
                    FROM dispatch_cards c
                    LEFT JOIN dispatch_card_labels cl ON c.id=cl.card_id
                    LEFT JOIN dispatch_labels l ON cl.label_id=l.id
                    GROUP BY c.id ORDER BY c.list_id,c.position
                ")->fetchAll(PDO::FETCH_ASSOC);
                foreach($cards_p as &$cp){
                    $cp['cover_url'] = $cp['cover_image'] ? (preg_match('#^https?://#',$cp['cover_image'])?$cp['cover_image']:UPLOAD_URL.$cp['cover_image']) : null;
                    $cp['assigned_agents'] = $cp['assigned_agents'] ? (json_decode($cp['assigned_agents'],true)?:[]) : [];
                    $cp['grade_tags']     = $cp['grade_tags']     ? json_decode($cp['grade_tags'],true)     : [];
                    $cp['code_ten_tags']  = $cp['code_ten_tags']  ? json_decode($cp['code_ten_tags'],true)  : [];
                    $cp['other_tags']     = $cp['other_tags']     ? json_decode($cp['other_tags'],true)     : [];
                    $cp['labels'] = [];
                    if($cp['labels_raw']) foreach(explode(';;',$cp['labels_raw']) as $lr){ $parts=explode('|',$lr,3); if(count($parts)===3) $cp['labels'][]=['id'=>$parts[0],'name'=>$parts[1],'color'=>$parts[2]]; }
                    unset($cp['labels_raw']);
                }
                unset($cp);
                $hash = md5(json_encode(array_map(fn($c)=>$c['id'].':'.$c['updated_at'],$cards_p)).json_encode(array_map(fn($l)=>$l['id'].':'.$l['name'].':'.$l['color'],$lists_p)));
                echo json_encode(['success'=>true,'hash'=>$hash,'lists'=>$lists_p,'cards'=>$cards_p]);
                break;
            default: echo json_encode(['success'=>false,'error'=>'Unknown action']);
        }
    }catch(Exception $e){echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}
    exit;
}

/* ─── LOAD DATA ─────────────────────────────────────────────── */
$lists=$db->query("SELECT * FROM dispatch_lists ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
foreach($lists as &$list){
    $s=$db->prepare("
        SELECT c.*,
            GROUP_CONCAT(l.id,'|',l.name,'|',l.color SEPARATOR ';;') as labels_raw,
            (SELECT COUNT(*) FROM dispatch_checklist_items i JOIN dispatch_checklists cl ON i.checklist_id=cl.id WHERE cl.card_id=c.id) as total_items,
            (SELECT COUNT(*) FROM dispatch_checklist_items i JOIN dispatch_checklists cl ON i.checklist_id=cl.id WHERE cl.card_id=c.id AND i.is_done=1) as done_items
        FROM dispatch_cards c
        LEFT JOIN dispatch_card_labels cl ON c.id=cl.card_id
        LEFT JOIN dispatch_labels l ON cl.label_id=l.id
        WHERE c.list_id=? GROUP BY c.id ORDER BY c.position
    ");
    $s->execute([$list['id']]);
    $cards=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($cards as &$card){
        $card['labels']=[];
        if($card['labels_raw'])foreach(explode(';;',$card['labels_raw'])as $r){[$a,$b,$c]=explode('|',$r);$card['labels'][]=['id'=>$a,'name'=>$b,'color'=>$c];}
        $card['cover_url']=$card['cover_image']?(preg_match('#^https?://#',$card['cover_image'])?$card['cover_image']:UPLOAD_URL.$card['cover_image']):null;
        $card['grade_tags']=$card['grade_tags']?json_decode($card['grade_tags'],true):[];
        $card['code_ten_tags']=$card['code_ten_tags']?json_decode($card['code_ten_tags'],true):[];
        $card['other_tags']=$card['other_tags']?json_decode($card['other_tags'],true):[];
        $card['assigned_agents']=!empty($card['assigned_agents'])?(json_decode($card['assigned_agents'],true)?:[]):[];
    }
    $list['cards']=$cards;
}
unset($list, $card); // libérer les références PHP
$allLabels=$db->query("SELECT * FROM dispatch_labels ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$patrolVehicles=$db->query("SELECT * FROM dispatch_patrol_vehicles ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
$grades=$db->query("SELECT * FROM dispatch_grades ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Dispatch Board';
include __DIR__.'/includes/header.php';
?>
<style>
/* ===== VARIABLES ===== */
:root{
    --c:#00e5ff; --cbg:rgba(0,229,255,.1); --cbgb:rgba(0,229,255,.06);
    --bg:#060f1d; --s1:#0b1929; --s2:#0f2137; --s3:#172a46;
    --bd:rgba(0,229,255,.1); --bdh:rgba(0,229,255,.32);
    --tx:#e2eef8; --tx2:#8fafc8; --mu:#4e6a82;
    --crit:#ef4444; --haut:#f59e0b; --norm:#3b82f6; --faib:#22c55e;
    --r:12px;
}
<?php
/* ── Injection thème dynamique ── */
$th = $DISPATCH_THEME;
$accent  = preg_match('/^#[0-9a-fA-F]{6}$/', $th['accent']) ? $th['accent'] : '#00e5ff';
$accentR = hexdec(substr(ltrim($accent,'#'),0,2));
$accentG = hexdec(substr(ltrim($accent,'#'),2,2));
$accentB = hexdec(substr(ltrim($accent,'#'),4,2));
$colOpacity    = max(0,min(1,(float)$th['col_opacity']));
$topbarOpacity = max(0,min(1,(float)$th['topbar_opacity']));
$colBlur       = max(0,min(30,(int)$th['col_blur']));
$cardRadius    = max(4,min(24,(int)$th['card_radius']));
echo ":root{
    --c:{$accent};
    --cbg:rgba({$accentR},{$accentG},{$accentB},.1);
    --cbgb:rgba({$accentR},{$accentG},{$accentB},.06);
    --bd:rgba({$accentR},{$accentG},{$accentB},.12);
    --bdh:rgba({$accentR},{$accentG},{$accentB},.35);
    --r:{$cardRadius}px;
    --th-col-opacity:{$colOpacity};
    --th-topbar-opacity:{$topbarOpacity};
    --th-col-blur:{$colBlur}px;
}\n";
?>

/* ===== BOARD ===== */
.db-outer{
    position:fixed; top:0; left:262px; right:0; bottom:0;
    display:flex; flex-direction:column; overflow:hidden;
    <?php
    $th = $DISPATCH_THEME;
    if ($th['bg_type'] === 'image' && !empty($th['bg_image'])) {
        $url = htmlspecialchars($th['bg_image']);
        $ov  = max(0,min(1,(float)$th['bg_overlay']));
        echo "background: url('{$url}') center/cover no-repeat fixed;";
    } elseif ($th['bg_type'] === 'solid') {
        $bgc = preg_match('/^#[0-9a-fA-F]{6}$/',$th['bg_color']) ? $th['bg_color'] : '#060f1d';
        echo "background:{$bgc};";
    } else {
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $th['accent']) ? $th['accent'] : '#00e5ff';
        $r=hexdec(substr(ltrim($accent,'#'),0,2));
        $g=hexdec(substr(ltrim($accent,'#'),2,2));
        $b=hexdec(substr(ltrim($accent,'#'),4,2));
        $bgc = preg_match('/^#[0-9a-fA-F]{6}$/',$th['bg_color']) ? $th['bg_color'] : '#060f1d';
        echo "background:
            radial-gradient(ellipse 55% 35% at 10% 55%, rgba({$r},{$g},{$b},.05), transparent),
            radial-gradient(ellipse 45% 55% at 90% 15%, rgba(0,80,220,.06), transparent),
            {$bgc};";
    }
    ?>
}
<?php if ($DISPATCH_THEME['bg_type'] === 'image' && !empty($DISPATCH_THEME['bg_image'])): ?>
.db-outer::before{
    content:''; position:absolute; inset:0; z-index:0;
    background:rgba(0,0,0,<?php echo max(0,min(1,(float)$DISPATCH_THEME['bg_overlay'])); ?>);
    pointer-events:none;
}
.db-bar,.db-scroll{ position:relative; z-index:1; }
<?php endif; ?>

/* ===== TOPBAR ===== */
.db-bar{
    flex-shrink:0; display:flex; align-items:center; gap:.65rem;
    padding:.65rem 1.2rem; height:56px;
    background:rgba(6,15,29,var(--th-topbar-opacity)); border-bottom:1px solid var(--bd);
    backdrop-filter:blur(14px); z-index:60;
}
.db-logo{
    font-family:'Orbitron',monospace; font-weight:800; font-size:.85rem;
    color:var(--c); letter-spacing:2.5px; white-space:nowrap;
    text-shadow:0 0 16px rgba(0,229,255,.5);
    display:flex; align-items:center; gap:.45rem;
}
.db-search{ position:relative; flex:1; max-width:260px; }
.db-search input{
    width:100%; padding:.42rem .85rem .42rem 2.1rem;
    background:rgba(255,255,255,.04); border:1px solid var(--bd);
    border-radius:8px; color:var(--tx);
    font-family:'Chakra Petch',sans-serif; font-size:.8rem;
    transition:border-color .2s, box-shadow .2s;
}
.db-search input::placeholder{ color:var(--mu); }
.db-search input:focus{ outline:none; border-color:var(--c); box-shadow:0 0 0 3px rgba(0,229,255,.1); }
.db-search::before{
    content:'\f002'; font-family:'Font Awesome 6 Free'; font-weight:900;
    position:absolute; left:.65rem; top:50%; transform:translateY(-50%);
    color:var(--mu); font-size:.72rem; pointer-events:none;
}
.db-filters{ display:flex; gap:.3rem; }
.fb{
    padding:.3rem .65rem; border-radius:6px; border:1px solid var(--bd);
    background:rgba(255,255,255,.03); color:var(--mu);
    font-family:'Chakra Petch',sans-serif; font-size:.7rem; font-weight:700;
    cursor:pointer; transition:all .18s; white-space:nowrap;
}
.fb:hover{ border-color:var(--bdh); color:var(--tx); }
.fb.on{ border-color:var(--c); color:var(--c); background:var(--cbg); }
.db-acts{ margin-left:auto; display:flex; gap:.35rem; }

/* ===== BUTTONS ===== */
.btn{
    padding:.38rem .85rem; border-radius:7px; border:none;
    font-family:'Chakra Petch',sans-serif; font-size:.76rem; font-weight:700;
    cursor:pointer; display:inline-flex; align-items:center; gap:.35rem;
    transition:all .18s; white-space:nowrap;
}
.btn-p{ background:linear-gradient(135deg,rgba(0,229,255,.18),rgba(0,100,200,.18)); border:1px solid rgba(0,229,255,.35); color:var(--c); }
.btn-p:hover{ background:linear-gradient(135deg,rgba(0,229,255,.28),rgba(0,100,200,.28)); box-shadow:0 0 14px rgba(0,229,255,.18); }
.btn-g{ background:transparent; border:1px solid transparent; color:var(--mu); }
.btn-g:hover{ background:rgba(255,255,255,.05); border-color:var(--bd); color:var(--tx); }
.btn-r{ background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.28); color:#ef4444; }
.btn-r:hover{ background:rgba(239,68,68,.2); }
.btn-sm{ padding:.28rem .6rem; font-size:.7rem; }

/* ===== BOARD SCROLL ===== */
.db-scroll{
    flex:1;
    overflow-x:auto; overflow-y:hidden;
    padding:1rem 1.2rem 1.1rem;
    display:flex;
    align-items:flex-start;
    gap:.8rem;
    scrollbar-width:auto;
    scrollbar-color:rgba(0,229,255,.55) rgba(255,255,255,.04);
}
/* ── Barre horizontale : +3px de hauteur, track cliquable, thumb contrasté ── */
.db-scroll::-webkit-scrollbar{ height:8px; }
.db-scroll::-webkit-scrollbar-track{ background:rgba(255,255,255,.035); border-radius:4px; margin:0 6px; }
.db-scroll::-webkit-scrollbar-thumb{
    background:linear-gradient(90deg,rgba(0,229,255,.5),rgba(0,180,255,.4));
    border-radius:4px; border:1px solid rgba(0,229,255,.2);
    min-width:60px; box-shadow:0 0 10px rgba(0,229,255,.25);
    transition:background .15s;
}
.db-scroll::-webkit-scrollbar-thumb:hover{ background:linear-gradient(90deg,rgba(0,229,255,.75),rgba(0,180,255,.6)); box-shadow:0 0 14px rgba(0,229,255,.45); }
.db-scroll::-webkit-scrollbar-thumb:active{ background:var(--c); }

/* ===== COLUMN ===== */
.list-col{
    flex-shrink:0; width:290px;
    display:flex; flex-direction:column;
    border-radius:var(--r); background:rgba(11,25,41,var(--th-col-opacity));
    border:1px solid var(--bd); backdrop-filter:blur(var(--th-col-blur));
    transition:border-color .22s, box-shadow .22s;
}
.list-col.dov{ border-color:var(--c); box-shadow:0 0 0 2px rgba(0,229,255,.18); }

.col-hdr{
    flex-shrink:0;
    display:flex; align-items:center; gap:.4rem;
    padding:.72rem .8rem .6rem; border-bottom:2px solid transparent;
    cursor:grab; user-select:none; border-radius:var(--r) var(--r) 0 0;
}
.col-hdr:active{ cursor:grabbing; }
.col-dot{ width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.col-name{
    flex:1; font-family:'Orbitron',monospace; font-size:.65rem; font-weight:700;
    letter-spacing:.9px; color:var(--tx); background:none; border:none; outline:none;
    cursor:pointer; padding:2px 3px; border-radius:4px; transition:background .18s;
}
.col-name:hover{ background:rgba(255,255,255,.05); }
.col-name:focus{ background:rgba(255,255,255,.08); cursor:text; }
.col-cnt{
    font-family:'JetBrains Mono',monospace; font-size:.62rem; color:var(--mu);
    background:rgba(255,255,255,.06); padding:1px 6px; border-radius:8px;
}
.col-more{
    background:none; border:none; color:var(--mu); cursor:pointer;
    padding:2px 5px; border-radius:4px; font-size:.82rem; transition:all .14s;
}
.col-more:hover{ background:rgba(255,255,255,.08); color:var(--tx); }

/* ── zone scrollable des cartes ── */
.cards-wrap{
    /* max-height = viewport - topbar(56px) - col-header(~48px) - add-btn(~44px) - padding(32px) */
    max-height: calc(100vh - 200px);
    overflow-y: auto;
    overflow-x: hidden;
    padding:.45rem .45rem;
    display:flex; flex-direction:column; gap:.4rem;
    scrollbar-width: thin;
    scrollbar-color: rgba(0,229,255,.5) rgba(255,255,255,.06);
}
.cards-wrap::-webkit-scrollbar{ width:5px; }
.cards-wrap::-webkit-scrollbar-track{ background:rgba(255,255,255,.05); border-radius:3px; }
.cards-wrap::-webkit-scrollbar-thumb{ background:rgba(0,229,255,.45); border-radius:3px; }
.cards-wrap::-webkit-scrollbar-thumb:hover{ background:rgba(0,229,255,.75); }

.drop-ph{
    height:54px; border:2px dashed rgba(0,229,255,.22);
    border-radius:9px; background:rgba(0,229,255,.03); pointer-events:none;
}

/* ===== CARD ===== */
.dcard{
    flex-shrink:0; /* NE PAS comprimer les cartes - déclenche le scroll */
    background:var(--s2); border:1px solid rgba(255,255,255,.065);
    border-radius:10px; cursor:pointer; overflow:hidden; position:relative;
    transition:transform .18s, border-color .18s, box-shadow .18s;
}
.dcard:hover{ transform:translateY(-2px); border-color:rgba(0,229,255,.22); box-shadow:0 6px 24px rgba(0,0,0,.5); }
.dcard.dragging{ opacity:.3; transform:scale(.97); }
.dcard.fout{ opacity:.12; pointer-events:none; }

/* priority stripe */
.dcard::after{
    content:''; position:absolute; left:0; top:0; bottom:0; width:3px; border-radius:3px 0 0 3px;
}
.dcard[data-p="critique"]::after{ background:var(--crit); }
.dcard[data-p="haute"]::after{ background:var(--haut); }
.dcard[data-p="normale"]::after{ background:var(--norm); }
.dcard[data-p="faible"]::after{ background:var(--faib); }

/* card color custom background */
.dcard[data-bg]{ background: var(--card-bg, var(--s2)); }

/* card description preview on card */
.card-desc-prev{
    font-family:'Chakra Petch',sans-serif; font-size:.72rem; color:var(--tx2);
    line-height:1.5; margin-bottom:.38rem; opacity:.8;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}

/* image preview badge on card */
.card-img-badge{
    position:absolute; top:6px; right:8px; z-index:2;
    background:rgba(0,0,0,.52); border:1px solid rgba(255,255,255,.14);
    border-radius:6px; padding:3px 7px;
    font-family:'JetBrains Mono',monospace; font-size:.6rem; color:#fff;
    display:flex; align-items:center; gap:4px; transition:background .15s;
    cursor:pointer;
}
.card-img-badge:hover{ background:rgba(0,229,255,.35); border-color:var(--c); }

/* ── LIGHTBOX ── */
.lbx-ov{
    position:fixed; inset:0; background:rgba(0,0,0,.93); backdrop-filter:blur(10px);
    z-index:9000; display:flex; align-items:center; justify-content:center;
    cursor:zoom-out; padding:1rem;
}
.lbx-ov.hidden{ display:none; }
.lbx-img{
    max-width:90vw; max-height:88vh; border-radius:12px;
    box-shadow:0 30px 90px rgba(0,0,0,.85); cursor:default;
    animation:lbxIn .2s cubic-bezier(.22,1,.36,1); display:block; object-fit:contain;
}
@keyframes lbxIn{ from{opacity:0;transform:scale(.94)} to{opacity:1;transform:none} }
.lbx-close{
    position:fixed; top:1.1rem; right:1.3rem;
    background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2);
    border-radius:50%; width:36px; height:36px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:.95rem; transition:background .15s; z-index:9001;
}
.lbx-close:hover{ background:rgba(255,255,255,.22); }

/* priority toggle buttons in modal */
.prio-btns{ display:flex; gap:.3rem; flex-wrap:wrap; }
.prio-btn{
    padding:.3rem .65rem; border-radius:8px; cursor:pointer;
    font-family:'Chakra Petch',sans-serif; font-size:.72rem; font-weight:700;
    border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04);
    color:var(--mu); transition:all .14s;
}
.prio-btn:hover{ border-color:rgba(255,255,255,.2); color:var(--tx); }
.prio-btn.on{ color:#fff; }
.prio-btn.pb-none.on{ background:rgba(100,116,139,.2); border-color:rgba(100,116,139,.4); color:#94a3b8; }
.prio-btn.pb-faible.on{ background:rgba(34,197,94,.15); border-color:rgba(34,197,94,.5); color:#22c55e; }
.prio-btn.pb-normale.on{ background:rgba(59,130,246,.15); border-color:rgba(59,130,246,.5); color:#60a5fa; }
.prio-btn.pb-haute.on{ background:rgba(245,158,11,.15); border-color:rgba(245,158,11,.5); color:#f59e0b; }
.prio-btn.pb-critique.on{ background:rgba(239,68,68,.15); border-color:rgba(239,68,68,.5); color:#ef4444; }
.grade-btn{ display:inline-flex; align-items:center; }
.g-lbl{ pointer-events:none; }
.g-rm{
    display:none; align-items:center; justify-content:center;
    width:14px; height:14px; border-radius:50%; margin-left:5px;
    background:rgba(255,255,255,.18); font-size:.52rem; line-height:1;
    transition:background .12s; cursor:pointer; flex-shrink:0;
    pointer-events:all;
}
.grade-btn.on .g-rm{ display:inline-flex; }
.g-rm:hover{ background:rgba(239,68,68,.6) !important; }

/* card color picker in modal */
.card-color-row{
    display:flex; align-items:center; gap:.65rem; flex-wrap:wrap; margin-top:.4rem;
}
.cc-swatch{
    width:24px; height:24px; border-radius:6px; cursor:pointer;
    border:2px solid transparent; transition:transform .12s, border-color .12s; flex-shrink:0;
}
.cc-swatch:hover{ transform:scale(1.2); }
.cc-swatch.on{ border-color:#fff; transform:scale(1.15); }
.cc-swatch.none{ background:transparent; border:2px dashed rgba(255,255,255,.2);
    display:flex; align-items:center; justify-content:center; color:var(--mu); font-size:.7rem; }
.cc-swatch.none.on{ border-color:var(--c); color:var(--c); }
.cc-custom-btn{
    padding:.22rem .6rem; border-radius:6px; border:1px solid rgba(255,255,255,.1);
    background:rgba(255,255,255,.05); color:var(--mu); font-family:'Chakra Petch',sans-serif;
    font-size:.7rem; cursor:pointer; transition:all .15s;
}
.cc-custom-btn:hover{ border-color:rgba(0,229,255,.3); color:var(--c); }
/* cover */
.card-cover{ width:100%; height:105px; object-fit:cover; display:block; cursor:zoom-in; }
.card-cover-wrap{ position:relative; overflow:hidden; }
.card-cover-ttl{
    position:absolute; bottom:0; left:0; right:0;
    background:linear-gradient(transparent, rgba(6,15,29,.85));
    padding:.4rem .7rem .5rem;
    font-family:'Chakra Petch',sans-serif; font-size:.8rem; font-weight:700;
    color:#fff; text-shadow:0 1px 4px rgba(0,0,0,.7); word-break:break-word;
    pointer-events:none;
}
.card-zoom-btn{
    position:absolute; top:6px; right:6px; z-index:3;
    background:rgba(0,0,0,.55); border:1px solid rgba(255,255,255,.18);
    border-radius:6px; width:26px; height:26px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:.72rem; transition:background .15s, transform .15s;
}
.card-zoom-btn:hover{ background:rgba(0,229,255,.45); border-color:var(--c); transform:scale(1.1); }

/* card body */
.card-body{ padding:.6rem .72rem .55rem .82rem; }

.card-lbls{ display:flex; flex-wrap:wrap; gap:3px; margin-bottom:.42rem; }
.card-lbl{
    padding:2px 8px; border-radius:4px;
    font-family:'Chakra Petch',sans-serif; font-size:.62rem; font-weight:800; letter-spacing:.3px;
    color:rgba(0,0,0,.85);
}

.card-title{
    font-family:'Chakra Petch',sans-serif; font-size:.84rem; font-weight:700;
    color:var(--tx); line-height:1.4; word-break:break-word; margin-bottom:.42rem;
}

/* tags section */
.card-tags{ display:flex; flex-wrap:wrap; gap:.28rem; margin-bottom:.35rem; }
.ctag{
    display:inline-flex; align-items:center; gap:.25rem;
    padding:2px 7px; border-radius:10px;
    font-family:'JetBrains Mono',monospace; font-size:.6rem; font-weight:700;
    white-space:nowrap;
}
.ct-gray{ background:rgba(100,116,139,.14); color:#94a3b8; border:1px solid rgba(100,116,139,.22); }
.ct-green{ background:rgba(34,197,94,.14); color:#22c55e; border:1px solid rgba(34,197,94,.22); }
.ct-red{ background:rgba(239,68,68,.14); color:#ef4444; border:1px solid rgba(239,68,68,.22); }
.ct-orange{ background:rgba(249,115,22,.14); color:#f97316; border:1px solid rgba(249,115,22,.22); }
.ct-blue{ background:rgba(59,130,246,.14); color:#60a5fa; border:1px solid rgba(59,130,246,.22); }
.ct-purple{ background:rgba(139,92,246,.14); color:#a78bfa; border:1px solid rgba(139,92,246,.22); }
.ct-yellow{ background:rgba(234,179,8,.14); color:#eab308; border:1px solid rgba(234,179,8,.22); }
.ct-grade{ background:rgba(0,229,255,.1); color:var(--c); border:1px solid rgba(0,229,255,.2); }
.ct-veh{ background:rgba(139,92,246,.1); color:#c4b5fd; border:1px solid rgba(139,92,246,.2); }

/* card footer */
.card-foot{ display:flex; flex-wrap:wrap; gap:.28rem; align-items:center; }
.cfoot-badge{
    display:inline-flex; align-items:center; gap:2px;
    font-family:'JetBrains Mono',monospace; font-size:.6rem; color:var(--mu);
    background:rgba(255,255,255,.04); padding:2px 6px; border-radius:5px;
}
.cfoot-badge.ov{ color:#ef4444; background:rgba(239,68,68,.1); }
.cfoot-badge.ds{ color:#f59e0b; background:rgba(245,158,11,.1); }
.prio-lbl{
    font-size:.58rem; font-weight:800; padding:2px 6px; border-radius:4px; text-transform:uppercase; letter-spacing:.4px;
}
.prio-lbl.critique{ background:rgba(239,68,68,.14); color:#ef4444; }
.prio-lbl.haute{ background:rgba(245,158,11,.14); color:#f59e0b; }
.prio-lbl.normale{ background:rgba(59,130,246,.14); color:#3b82f6; }
.prio-lbl.faible{ background:rgba(34,197,94,.14); color:#22c55e; }
.cl-mini{ display:flex; align-items:center; gap:5px; margin-top:.35rem; }
.cl-bar{ flex:1; height:3px; background:rgba(255,255,255,.07); border-radius:2px; overflow:hidden; }
.cl-fill{ height:100%; background:linear-gradient(90deg,var(--c),#0066ff); border-radius:2px; transition:width .3s; }
.cl-mini span{ font-family:'JetBrains Mono',monospace; font-size:.6rem; color:var(--mu); }

/* add card */
.add-card-row{
    flex-shrink:0; /* ne jamais compresser le bouton */
    display:flex; align-items:center; gap:.38rem;
    margin:.0 .45rem .45rem; padding:.45rem .65rem;
    border:1px dashed rgba(255,255,255,.07); border-radius:8px;
    color:var(--mu); font-family:'Chakra Petch',sans-serif; font-size:.76rem;
    cursor:pointer; transition:all .18s; background:none; width:calc(100% - .9rem);
}
.add-card-row:hover{ border-color:rgba(0,229,255,.28); color:var(--c); background:rgba(0,229,255,.04); }
.add-card-form{ flex-shrink:0; padding:0 .45rem .45rem; display:none; }
.add-card-form textarea{
    width:100%; background:rgba(255,255,255,.05); border:1px solid rgba(0,229,255,.2);
    border-radius:8px; padding:.55rem .65rem; color:var(--tx);
    font-family:'Chakra Petch',sans-serif; font-size:.8rem; resize:none; min-height:65px; outline:none;
}
.add-card-form textarea:focus{ border-color:var(--c); box-shadow:0 0 0 2px rgba(0,229,255,.1); }
.acf-row{ display:flex; gap:.35rem; margin-top:.4rem; }

/* add list */
.add-list-btn{
    flex-shrink:0; width:258px; padding:.65rem 1rem;
    background:rgba(255,255,255,.03); border:2px dashed rgba(0,229,255,.12);
    border-radius:var(--r); color:var(--mu); font-family:'Chakra Petch',sans-serif;
    font-size:.8rem; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:.5rem;
    transition:all .22s; align-self:flex-start;
}
.add-list-btn:hover{ border-color:rgba(0,229,255,.32); color:var(--c); background:var(--cbgb); }

/* ===== MODAL OVERLAY ===== */
.mov{
    position:fixed; inset:0; background:rgba(0,0,0,.8); backdrop-filter:blur(5px);
    z-index:1000; display:flex; align-items:flex-start; justify-content:center;
    padding:1.5rem 1rem; overflow-y:auto;
}
.mov.hidden{ display:none; }
.mbox{
    background:var(--s1); border:1px solid rgba(0,229,255,.14);
    border-radius:18px; width:100%; max-width:840px;
    box-shadow:0 28px 80px rgba(0,0,0,.75);
    animation:min .22s cubic-bezier(.22,1,.36,1);
}
@keyframes min{ from{ opacity:0; transform:translateY(-16px) scale(.985); } to{ opacity:1; transform:none; } }

.m-top{
    display:flex; align-items:flex-start; gap:.8rem; padding:1.2rem 1.2rem 0;
}
.m-ico{
    width:34px; height:34px; border-radius:8px; background:var(--cbg);
    border:1px solid rgba(0,229,255,.2); display:flex; align-items:center; justify-content:center;
    color:var(--c); font-size:.88rem; flex-shrink:0;
}
.m-title-inp{
    flex:1; background:none; border:none; outline:none;
    font-family:'Chakra Petch',sans-serif; font-size:1.08rem; font-weight:700;
    color:var(--tx); line-height:1.35; padding:2px 4px; border-radius:5px;
    transition:background .18s; resize:none; width:100%;
}
.m-title-inp:hover{ background:rgba(255,255,255,.04); }
.m-title-inp:focus{ background:rgba(255,255,255,.06); }
.m-x{
    background:none; border:none; color:var(--mu); font-size:1.15rem;
    cursor:pointer; padding:4px; border-radius:6px; transition:all .14s; line-height:1;
}
.m-x:hover{ background:rgba(255,255,255,.07); color:var(--tx); }
.m-sub{ font-family:'JetBrains Mono',monospace; font-size:.68rem; color:var(--mu); padding:.2rem 1.2rem .35rem calc(1.2rem + 34px + .8rem); }

.m-body{ display:grid; grid-template-columns:1fr 180px; gap:1.1rem; padding:.8rem 1.2rem 1.2rem; }
.m-main{ display:flex; flex-direction:column; gap:1rem; }
.m-side{ display:flex; flex-direction:column; gap:.35rem; }

.m-label{
    font-family:'Orbitron',monospace; font-size:.6rem; font-weight:700;
    letter-spacing:1.5px; color:var(--mu); text-transform:uppercase;
    display:flex; align-items:center; gap:.4rem; margin-bottom:.45rem;
}
.m-label i{ color:var(--c); }

.minp, .msel{
    width:100%; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1);
    border-radius:7px; padding:.5rem .75rem; color:var(--tx);
    font-family:'Chakra Petch',sans-serif; font-size:.81rem; outline:none; transition:border-color .18s;
}
.minp:focus,.msel:focus{ border-color:rgba(0,229,255,.32); background:rgba(255,255,255,.07); }
.minp::placeholder{ color:var(--mu); }
.msel option{ background:#0b1929; }
.msel.pc{ border-color:rgba(239,68,68,.4); }
.msel.ph{ border-color:rgba(245,158,11,.4); }
.msel.pn{ border-color:rgba(59,130,246,.4); }
.msel.pf{ border-color:rgba(34,197,94,.4); }

.g2{ display:grid; grid-template-columns:1fr 1fr; gap:.45rem; }
.g3{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:.45rem; }

/* ─── COVER ─── */
.cover-preview-wrap{ position:relative; margin-bottom:.45rem; }
.cover-preview{ width:100%; height:120px; object-fit:cover; border-radius:8px; display:block; border:1px solid rgba(255,255,255,.08); }
.cover-rm{ position:absolute; top:5px; right:5px; }
.cover-drop{
    border:2px dashed rgba(0,229,255,.18); border-radius:8px; padding:.9rem;
    text-align:center; cursor:pointer; transition:all .18s; background:rgba(0,229,255,.02);
}
.cover-drop:hover{ border-color:rgba(0,229,255,.38); background:var(--cbgb); }
.cover-drop input{ display:none; }
.cover-drop p{ font-family:'Chakra Petch',sans-serif; font-size:.75rem; color:var(--mu); margin:0; }
.cover-drop i{ font-size:1.4rem; color:var(--mu); margin-bottom:.3rem; display:block; }

/* ─── LABELS ─── */
.lbl-grid{ display:flex; flex-wrap:wrap; gap:.3rem; }
.lbl-tog{
    display:inline-flex; align-items:center; gap:.28rem;
    padding:.25rem .62rem; border-radius:5px;
    font-family:'Chakra Petch',sans-serif; font-size:.68rem; font-weight:700;
    cursor:pointer; border:2px solid transparent; transition:all .14s;
    color:rgba(0,0,0,.85); opacity:.5;
}
.lbl-tog.on{ opacity:1; border-color:rgba(255,255,255,.55); }

/* ─── GRADES ─── */
.grade-grid{ display:flex; flex-wrap:wrap; gap:.3rem; }
.grade-btn{
    padding:.26rem .62rem; border-radius:10px; cursor:pointer;
    font-family:'Chakra Petch',sans-serif; font-size:.68rem; font-weight:700;
    border:1px solid rgba(0,229,255,.16); color:var(--mu); background:var(--cbgb);
    transition:all .14s;
}
.grade-btn:hover{ border-color:rgba(0,229,255,.38); color:var(--c); }
.grade-btn.on{ background:rgba(0,229,255,.15); border-color:var(--c); color:var(--c); }

/* ─── TAG DROPDOWN ─── */
.tag-dd{ position:relative; }
.tag-trigger{
    width:100%; display:flex; align-items:center; justify-content:space-between;
    background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1);
    border-radius:7px; padding:.5rem .75rem; cursor:pointer;
    font-family:'Chakra Petch',sans-serif; font-size:.8rem; color:var(--mu);
    transition:border-color .18s; user-select:none;
}
.tag-trigger:hover{ border-color:rgba(0,229,255,.28); color:var(--tx); }
.tag-trigger.open{ border-color:rgba(0,229,255,.32); color:var(--tx); }
.tag-panel{
    position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:300;
    background:var(--s2); border:1px solid rgba(0,229,255,.2); border-radius:9px;
    padding:.4rem; box-shadow:0 14px 45px rgba(0,0,0,.65);
    display:none; flex-direction:column; max-height:210px;
}
.tag-panel.open{ display:flex; }
.tag-srch{
    background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
    border-radius:6px; padding:.38rem .6rem; color:var(--tx);
    font-family:'Chakra Petch',sans-serif; font-size:.78rem; outline:none; margin-bottom:.3rem;
}
.tag-srch:focus{ border-color:rgba(0,229,255,.28); }
.tag-opts{ overflow-y:auto; display:flex; flex-direction:column; gap:2px; }
.tag-opts::-webkit-scrollbar{ width:3px; }
.tag-opts::-webkit-scrollbar-thumb{ background:rgba(0,229,255,.15); }
.tag-opt{
    display:flex; align-items:center; gap:.45rem; padding:.35rem .55rem;
    border-radius:6px; cursor:pointer; transition:background .12s;
}
.tag-opt:hover{ background:rgba(255,255,255,.06); }
.tag-opt input{ width:14px; height:14px; accent-color:var(--c); cursor:pointer; flex-shrink:0; }
.tag-opt-lbl{ display:flex; align-items:center; gap:.38rem; font-family:'Chakra Petch',sans-serif; font-size:.76rem; color:var(--tx); }
.tag-dot{ width:7px; height:7px; border-radius:50%; flex-shrink:0; }

.sel-tags{ display:flex; flex-wrap:wrap; gap:.28rem; margin-top:.4rem; }
.sel-tags .ctag{ cursor:pointer; transition:transform .12s, opacity .12s; position:relative; padding-right:1.4rem; }
.sel-tags .ctag::after{ content:'\f00d'; font-family:'Font Awesome 6 Free'; font-weight:900; position:absolute; right:.4rem; top:50%; transform:translateY(-50%); font-size:.55rem; opacity:.6; transition:opacity .12s; }
.sel-tags .ctag:hover{ transform:translateY(-1px); }
.sel-tags .ctag:hover::after{ opacity:1; }

/* ─── PLAQUES VEHICULE ─── */
.plate-block{
    margin-top:.5rem; background:rgba(255,255,255,.025); border:1px solid rgba(255,255,255,.07);
    border-radius:9px; padding:.65rem .75rem;
    animation:plateSlide .25s ease;
}
@keyframes plateSlide{ from{opacity:0;transform:translateY(-4px);} to{opacity:1;transform:translateY(0);} }
.plate-head{
    display:flex; align-items:center; justify-content:space-between; margin-bottom:.55rem;
    font-family:'Chakra Petch',sans-serif; font-size:.74rem; color:var(--tx2);
}
.plate-head-l{ display:flex; align-items:center; gap:.4rem; }
.plate-head-l > i{ color:var(--c); font-size:.78rem; }
.plate-current{
    font-family:'JetBrains Mono',monospace; font-size:.82rem; font-weight:700;
    color:var(--c); padding:.15rem .55rem; background:rgba(0,229,255,.08);
    border:1px solid rgba(0,229,255,.22); border-radius:5px; letter-spacing:.5px;
}
.plate-current.empty{ color:var(--mu); background:transparent; border-color:rgba(255,255,255,.08); font-weight:500; letter-spacing:0; }
.plate-clear{
    background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.28); color:#ef4444;
    width:24px; height:24px; border-radius:5px; cursor:pointer; display:grid; place-items:center;
    font-size:.7rem; transition:all .14s;
}
.plate-clear:hover{ background:rgba(239,68,68,.2); }
.plate-grid{
    display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr));
    gap:.35rem; max-height:170px; overflow-y:auto; padding:.15rem; border-radius:6px;
    background:rgba(0,0,0,.18);
}
.plate-grid::-webkit-scrollbar{ width:5px; }
.plate-grid::-webkit-scrollbar-thumb{ background:rgba(0,229,255,.35); border-radius:3px; }
.plate-empty{
    grid-column:1/-1; padding:1.2rem .8rem; text-align:center; color:var(--mu);
    font-family:'Chakra Petch',sans-serif; font-size:.78rem; font-style:italic;
}
.plate-chip{
    font-family:'JetBrains Mono',monospace; font-size:.75rem; font-weight:700;
    padding:.5rem .55rem; border-radius:6px; text-align:center; letter-spacing:.6px;
    border:1px solid; cursor:pointer; transition:all .15s; user-select:none;
    position:relative; overflow:hidden; line-height:1.1;
}
.plate-chip.free{
    background:rgba(34,197,94,.08); border-color:rgba(34,197,94,.32); color:#86efac;
}
.plate-chip.free:hover{
    background:rgba(34,197,94,.18); border-color:rgba(34,197,94,.55);
    transform:translateY(-1px); box-shadow:0 4px 12px rgba(34,197,94,.18);
}
.plate-chip.free.selected{
    background:linear-gradient(135deg,rgba(34,197,94,.35),rgba(16,185,129,.32));
    border-color:#22c55e; color:#fff; box-shadow:0 0 0 2px rgba(34,197,94,.25), 0 6px 16px rgba(34,197,94,.28);
}
.plate-chip.free.selected::before{
    content:'\f00c'; font-family:'Font Awesome 6 Free'; font-weight:900;
    position:absolute; top:2px; right:4px; font-size:.55rem; opacity:.85;
}
.plate-chip.busy{
    background:rgba(100,116,139,.06); border-color:rgba(100,116,139,.18);
    color:var(--mu); cursor:not-allowed; opacity:.55;
    text-decoration:line-through;
}
.plate-chip.busy::after{
    content:'\f023'; font-family:'Font Awesome 6 Free'; font-weight:900;
    position:absolute; top:2px; right:4px; font-size:.5rem; opacity:.6;
}
.plate-legend{
    display:flex; gap:.85rem; margin-top:.5rem; font-family:'Chakra Petch',sans-serif;
    font-size:.65rem; color:var(--mu); flex-wrap:wrap;
}
.plate-legend span{ display:inline-flex; align-items:center; gap:.32rem; }
.plate-dot{ width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.plate-dot.ok{ background:#22c55e; box-shadow:0 0 6px rgba(34,197,94,.6); }
.plate-dot.busy{ background:#64748b; }

/* ─── CHECKLIST GLOBAL TOGGLE BUTTON ─── */
.sbt.cl-global-tog.active{
    background:linear-gradient(135deg,rgba(249,115,22,.22),rgba(245,101,16,.22)) !important;
    border-color:rgba(249,115,22,.55) !important;
    color:#fb923c !important;
}
.sbt.cl-global-tog.active:hover{
    background:linear-gradient(135deg,rgba(249,115,22,.32),rgba(245,101,16,.32)) !important;
}

.cl-block.global{
    background:linear-gradient(135deg,rgba(249,115,22,.05),rgba(245,158,11,.04));
    border-color:rgba(249,115,22,.22);
}
.cl-block.global .cl-hdr-t i{ color:#fb923c !important; }
.cl-block.global .cl-prog-f{ background:linear-gradient(90deg,#fb923c,#f59e0b) !important; }
.cl-block.global .cl-global-tag{
    display:inline-flex; align-items:center; gap:.25rem; font-size:.58rem; font-weight:700;
    padding:.1rem .45rem; border-radius:50px;
    background:rgba(249,115,22,.18); color:#fb923c; border:1px solid rgba(249,115,22,.32);
    text-transform:uppercase; letter-spacing:.5px; margin-left:.4rem;
}

/* ─── GLOBAL CL MANAGER MODAL ─── */
.gcl-empty{
    padding:2rem 1rem; text-align:center; color:var(--mu);
    font-family:'Chakra Petch',sans-serif; font-size:.82rem;
}
.gcl-empty i{ display:block; font-size:1.8rem; margin-bottom:.5rem; opacity:.3; }
.gcl-block{
    background:rgba(255,255,255,.025); border:1px solid rgba(255,255,255,.07);
    border-radius:9px; padding:.7rem .8rem; margin-bottom:.55rem;
}
.gcl-block-hdr{ display:flex; align-items:center; gap:.5rem; margin-bottom:.5rem; }
.gcl-block-ttl{
    flex:1; font-family:'Chakra Petch',sans-serif; font-weight:700; font-size:.85rem;
    color:var(--tx); display:flex; align-items:center; gap:.4rem;
}
.gcl-block-ttl i{ color:#fb923c; font-size:.74rem; }
.gcl-cnt{
    font-family:'JetBrains Mono',monospace; font-size:.65rem; color:var(--mu);
    background:rgba(255,255,255,.06); padding:1px 7px; border-radius:8px;
}
.gcl-items{ display:flex; flex-direction:column; gap:.18rem; margin-bottom:.42rem; }
.gcl-item{
    display:flex; align-items:center; gap:.45rem; padding:.32rem .55rem;
    background:rgba(0,0,0,.15); border-radius:5px; font-family:'Chakra Petch',sans-serif;
    font-size:.78rem; color:var(--tx);
}
.gcl-item span{ flex:1; }
.gcl-item .gcl-it-del{
    background:transparent; border:none; color:var(--mu); cursor:pointer; font-size:.7rem;
    padding:2px 4px; border-radius:3px; transition:all .14s;
}
.gcl-item .gcl-it-del:hover{ background:rgba(239,68,68,.15); color:#ef4444; }
.gcl-add{ display:flex; gap:.35rem; }
.gcl-add input{
    flex:1; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1);
    border-radius:6px; padding:.38rem .6rem; color:var(--tx);
    font-family:'Chakra Petch',sans-serif; font-size:.76rem; outline:none;
}
.gcl-add input:focus{ border-color:rgba(249,115,22,.4); }

/* ─── DESCRIPTION ─── */
.desc-ta{
    width:100%; min-height:80px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08);
    border-radius:8px; padding:.65rem .8rem; color:var(--tx);
    font-family:'Chakra Petch',sans-serif; font-size:.81rem; resize:vertical; outline:none; transition:border-color .18s;
}
.desc-ta:focus{ border-color:rgba(0,229,255,.28); background:rgba(255,255,255,.06); }

/* ─── CHECKLIST ─── */
.cl-block{ background:rgba(255,255,255,.025); border:1px solid rgba(255,255,255,.055); border-radius:9px; padding:.65rem .75rem; margin-bottom:.55rem; }
.cl-hdr{ display:flex; justify-content:space-between; align-items:center; margin-bottom:.38rem; }
.cl-hdr-t{ font-family:'Chakra Petch',sans-serif; font-size:.82rem; font-weight:700; color:var(--tx); display:flex; align-items:center; gap:.4rem; }
.cl-prog{ height:3px; background:rgba(255,255,255,.06); border-radius:2px; margin-bottom:.55rem; overflow:hidden; }
.cl-prog-f{ height:100%; background:linear-gradient(90deg,var(--c),#0066ff); border-radius:2px; transition:width .3s; }
.cl-item{ display:flex; align-items:center; gap:.5rem; padding:.3rem .28rem; border-radius:6px; cursor:pointer; transition:background .12s; }
.cl-item:hover{ background:rgba(255,255,255,.04); }
.cl-item input[type="checkbox"]{ width:14px; height:14px; accent-color:var(--c); cursor:pointer; flex-shrink:0; }
.cl-it-txt{ font-family:'Chakra Petch',sans-serif; font-size:.79rem; color:var(--tx); flex:1; }
.cl-it-txt.done{ text-decoration:line-through; color:var(--mu); }
.cl-it-del{ background:none; border:none; color:var(--mu); cursor:pointer; opacity:0; font-size:.68rem; padding:2px 4px; border-radius:4px; transition:all .12s; }
.cl-item:hover .cl-it-del{ opacity:1; }
.cl-it-del:hover{ color:#ef4444; background:rgba(239,68,68,.1); }
.cl-add{ display:flex; gap:.3rem; margin-top:.4rem; }
.cl-add-i{ flex:1; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08); border-radius:6px; padding:.38rem .6rem; color:var(--tx); font-family:'Chakra Petch',sans-serif; font-size:.77rem; outline:none; transition:border-color .18s; }
.cl-add-i:focus{ border-color:rgba(0,229,255,.28); }

/* ─── COMMENTS ─── */
.cmt{ display:flex; gap:.6rem; margin-bottom:.7rem; }
.cmt-av{ width:27px; height:27px; border-radius:7px; background:linear-gradient(135deg,rgba(0,229,255,.16),rgba(0,102,255,.16)); border:1px solid rgba(0,229,255,.26); display:flex; align-items:center; justify-content:center; font-family:'Orbitron',monospace; font-size:.58rem; font-weight:700; color:var(--c); flex-shrink:0; }
.cmt-body{ flex:1; }
.cmt-who{ font-family:'Chakra Petch',sans-serif; font-size:.72rem; font-weight:700; color:var(--c); display:flex; gap:.4rem; align-items:center; margin-bottom:.2rem; }
.cmt-when{ font-family:'JetBrains Mono',monospace; font-size:.6rem; color:var(--mu); font-weight:400; }
.cmt-txt{ background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.055); border-radius:0 8px 8px 8px; padding:.5rem .65rem; font-family:'Chakra Petch',sans-serif; font-size:.78rem; color:var(--tx); line-height:1.5; position:relative; }
.cmt-del{ background:none; border:none; color:var(--mu); cursor:pointer; font-size:.65rem; padding:2px 4px; border-radius:4px; position:absolute; top:5px; right:5px; transition:all .12s; }
.cmt-del:hover{ color:#ef4444; background:rgba(239,68,68,.1); }
.cmt-new{ display:flex; gap:.45rem; align-items:flex-start; }
.cmt-ta{ flex:1; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.07); border-radius:9px; padding:.55rem .75rem; color:var(--tx); font-family:'Chakra Petch',sans-serif; font-size:.78rem; resize:none; min-height:55px; outline:none; transition:border-color .18s; }
.cmt-ta:focus{ border-color:rgba(0,229,255,.26); }

/* ─── SIDE BUTTONS ─── */
.sbt{ width:100%; padding:.46rem .65rem; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07); border-radius:7px; color:var(--tx); font-family:'Chakra Petch',sans-serif; font-size:.74rem; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:.5rem; text-align:left; transition:all .18s; }
.sbt:hover{ background:rgba(255,255,255,.08); border-color:rgba(0,229,255,.2); color:var(--c); }
.sbt i{ color:var(--mu); font-size:.78rem; width:12px; }
.sbt:hover i{ color:var(--c); }
.sbt.dng{ border-color:rgba(239,68,68,.2); color:#ef4444; }
.sbt.dng i{ color:#ef4444; }
.sbt.dng:hover{ background:rgba(239,68,68,.1); }
.s-lbl{ font-family:'Orbitron',monospace; font-size:.55rem; font-weight:700; letter-spacing:1px; color:var(--mu); text-transform:uppercase; padding:.3rem 0 .12rem; }

/* ─── ADD LIST MODAL ─── */
.alm-ov{
    position:fixed;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(5px);
    z-index:3500;display:flex;align-items:center;justify-content:center;
}
.alm-ov.hidden{display:none;}
.alm-box{
    background:var(--s1);border:1px solid rgba(0,229,255,.18);
    border-radius:16px;padding:1.5rem;width:360px;
    box-shadow:0 28px 75px rgba(0,0,0,.8);
    animation:min .22s cubic-bezier(.22,1,.36,1);
}
.alm-head{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:1.1rem;
}
.alm-ttl{
    font-family:'Orbitron',monospace;font-size:.78rem;font-weight:800;
    letter-spacing:1.5px;color:var(--c);text-transform:uppercase;
    display:flex;align-items:center;gap:.45rem;
}
.alm-f-lbl{
    font-family:'Orbitron',monospace;font-size:.58rem;font-weight:700;
    letter-spacing:1px;color:var(--mu);text-transform:uppercase;
    display:block;margin-bottom:.35rem;
}
.alm-name-inp{
    width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
    border-radius:8px;padding:.58rem .8rem;color:var(--tx);
    font-family:'Chakra Petch',sans-serif;font-size:.9rem;outline:none;
    margin-bottom:1rem;transition:border-color .18s;
}
.alm-name-inp:focus{border-color:rgba(0,229,255,.4);}
.alm-color-trigger{
    display:flex;align-items:center;gap:.75rem;
    background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);
    border-radius:10px;padding:.65rem .85rem;cursor:pointer;
    transition:border-color .18s;margin-bottom:1rem;
}
.alm-color-trigger:hover{border-color:rgba(0,229,255,.3);}
.alm-color-swatch{width:32px;height:32px;border-radius:7px;border:1px solid rgba(255,255,255,.15);flex-shrink:0;transition:background .2s;}
.alm-color-label{font-family:'JetBrains Mono',monospace;font-size:.82rem;color:var(--tx2);}
.alm-color-arrow{margin-left:auto;color:var(--mu);font-size:.75rem;}
.alm-btns{display:flex;gap:.4rem;}

/* ─── COLOR PICKER ─── */
.cpk-ov{
    position:fixed;inset:0;background:rgba(0,0,0,.85);backdrop-filter:blur(6px);
    z-index:4000;display:flex;align-items:center;justify-content:center;
}
.cpk-ov.hidden{display:none;}
.cpk-box{
    background:var(--s1);border:1px solid rgba(0,229,255,.2);
    border-radius:18px;padding:1.4rem;width:310px;
    box-shadow:0 32px 90px rgba(0,0,0,.85);
    animation:min .22s cubic-bezier(.22,1,.36,1);
}
.cpk-head{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:1rem;
}
.cpk-ttl{
    font-family:'Orbitron',monospace;font-size:.72rem;font-weight:800;
    letter-spacing:1.5px;color:var(--c);text-transform:uppercase;
    display:flex;align-items:center;gap:.4rem;
}
/* SV canvas */
.cpk-sv-wrap{
    position:relative;width:100%;height:165px;border-radius:10px;overflow:hidden;
    cursor:crosshair;margin-bottom:.75rem;border:1px solid rgba(255,255,255,.07);
}
#cpkSV{display:block;width:100%;height:100%;}
.cpk-sv-cur{
    position:absolute;width:14px;height:14px;border-radius:50%;
    border:2.5px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.6),0 2px 6px rgba(0,0,0,.5);
    pointer-events:none;transform:translate(-50%,-50%);
}
/* Hue slider */
.cpk-hue-wrap{
    position:relative;height:14px;border-radius:7px;margin-bottom:.65rem;cursor:pointer;
    background:linear-gradient(to right,#f00,#ff0,#0f0,#0ff,#00f,#f0f,#f00);
    border:1px solid rgba(255,255,255,.08);
}
.cpk-hue-thumb{
    position:absolute;top:50%;width:18px;height:18px;border-radius:50%;
    border:2.5px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.5);
    transform:translate(-50%,-50%);pointer-events:none;
}
/* Presets */
.cpk-presets{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:.85rem;}
.cpk-pre{
    width:22px;height:22px;border-radius:5px;cursor:pointer;
    border:2px solid transparent;transition:transform .12s,border-color .12s;
}
.cpk-pre:hover{transform:scale(1.22);}
.cpk-pre.on{border-color:#fff;transform:scale(1.15);}
/* Hex row */
.cpk-hex-row{display:flex;gap:.5rem;align-items:center;margin-bottom:.9rem;}
.cpk-preview{
    width:38px;height:38px;border-radius:8px;
    border:1px solid rgba(255,255,255,.12);flex-shrink:0;transition:background .08s;
}
.cpk-hex{
    flex:1;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
    border-radius:7px;padding:.48rem .75rem;color:var(--tx);
    font-family:'JetBrains Mono',monospace;font-size:.88rem;outline:none;
    transition:border-color .18s;letter-spacing:.5px;
}
.cpk-hex:focus{border-color:rgba(0,229,255,.4);}
.cpk-btns{display:flex;gap:.4rem;}

/* ─── CTX MENU ─── */
.ctx{ position:fixed; z-index:3000; background:var(--s2); border:1px solid rgba(0,229,255,.15); border-radius:9px; padding:.3rem; min-width:148px; box-shadow:0 12px 38px rgba(0,0,0,.65); animation:min .14s; }
.ctx.hidden{ display:none; }
.ctx-i{ padding:.42rem .65rem; border-radius:5px; font-family:'Chakra Petch',sans-serif; font-size:.76rem; font-weight:600; color:var(--tx); cursor:pointer; display:flex; align-items:center; gap:.42rem; transition:background .12s; }
.ctx-i:hover{ background:rgba(255,255,255,.06); }
.ctx-i.dng{ color:#ef4444; }
.ctx-i.dng:hover{ background:rgba(239,68,68,.1); }
.ctx-i i{ width:12px; color:var(--mu); font-size:.74rem; }
.ctx-i.dng i{ color:#ef4444; }

/* ─── TOAST ─── */
.toast-c{ position:fixed; bottom:1.2rem; right:1.2rem; z-index:9999; display:flex; flex-direction:column; gap:.35rem; }
.toast{ padding:.6rem 1rem; border-radius:8px; font-family:'Chakra Petch',sans-serif; font-size:.78rem; font-weight:600; display:flex; align-items:center; gap:.4rem; animation:min .22s; max-width:290px; }
.ts{ background:rgba(34,197,94,.14); border:1px solid rgba(34,197,94,.26); color:#22c55e; }
.te{ background:rgba(239,68,68,.14); border:1px solid rgba(239,68,68,.26); color:#ef4444; }
.ti{ background:rgba(0,229,255,.1); border:1px solid rgba(0,229,255,.2); color:var(--c); }

/* ─── MANAGE MODAL ─── */
.mgr-list{ display:flex; flex-direction:column; gap:.3rem; max-height:240px; overflow-y:auto; margin-bottom:.65rem; }
.mgr-item{ display:flex; align-items:center; gap:.45rem; padding:.38rem .5rem; background:rgba(255,255,255,.03); border-radius:7px; }
.mgr-item span{ flex:1; font-family:'Chakra Petch',sans-serif; font-size:.8rem; color:var(--tx); }

/* separator */
.m-sep{ height:1px; background:rgba(255,255,255,.06); margin:.2rem 0; }

@media(max-width:1024px){ .db-outer{left:0;} .m-body{grid-template-columns:1fr;} }

/* ─── RESPONSIVE plaques & checklists globales ─── */
@media(max-width:768px){
    .plate-grid{ grid-template-columns:repeat(auto-fill,minmax(95px,1fr)); max-height:200px; }
    .plate-chip{ font-size:.7rem; padding:.45rem .35rem; }
    .plate-head{ flex-wrap:wrap; gap:.4rem; }
    .plate-current{ font-size:.74rem; }
    .plate-legend{ font-size:.6rem; }
    .gcl-add{ flex-wrap:wrap; }
    .gcl-add input{ flex-basis:100%; }
}
@media(max-width:480px){
    .plate-grid{ grid-template-columns:repeat(auto-fill,minmax(85px,1fr)); }
    .plate-chip{ font-size:.65rem; letter-spacing:.3px; }
    .sbt{ font-size:.7rem; padding:.5rem .55rem; }
    .sel-tags .ctag{ padding-right:1.25rem; }
}

/* ─── DELETE CONFIRM MODAL ─── */
.del-ov{position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.75);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;animation:delFadeIn .18s ease;}
.del-ov.hidden{display:none;}
@keyframes delFadeIn{from{opacity:0}to{opacity:1}}
.del-box{background:var(--s1);border:1px solid rgba(239,68,68,.3);border-radius:18px;padding:2rem 2rem 1.6rem;width:420px;max-width:90vw;text-align:center;box-shadow:0 32px 90px rgba(0,0,0,.85);animation:delSlideIn .22s cubic-bezier(.22,1,.36,1);}
@keyframes delSlideIn{from{transform:scale(.92) translateY(12px);opacity:0}to{transform:none;opacity:1}}
.del-ico{width:64px;height:64px;border-radius:50%;background:rgba(239,68,68,.12);border:2px solid rgba(239,68,68,.25);display:flex;align-items:center;justify-content:center;margin:0 auto 1.2rem;font-size:1.5rem;color:#ef4444;}
.del-title{font-family:'Orbitron',monospace;font-size:.95rem;font-weight:800;letter-spacing:.5px;color:var(--tx);margin-bottom:.65rem;}
.del-sub{font-family:'Chakra Petch',sans-serif;font-size:.8rem;color:var(--tx2);line-height:1.6;margin-bottom:1.5rem;}
.del-btns{display:flex;gap:.6rem;justify-content:center;}
.del-cancel{min-width:120px;justify-content:center;}
.del-confirm{min-width:140px;justify-content:center;background:linear-gradient(135deg,rgba(239,68,68,.22),rgba(220,38,38,.18));border:1px solid rgba(239,68,68,.4);color:#ef4444;transition:all .18s;}
.del-confirm:hover{background:rgba(239,68,68,.3);box-shadow:0 0 18px rgba(239,68,68,.2);}

/* ─── SYNC indicator ─── */
.sync-dot{width:7px;height:7px;border-radius:50%;background:var(--c);display:inline-block;margin-left:6px;opacity:.7;transition:opacity .3s;}
.sync-dot.pulse{animation:syncPulse .6s ease;}
@keyframes syncPulse{0%{transform:scale(1);opacity:.7}50%{transform:scale(1.8);opacity:1}100%{transform:scale(1);opacity:.7}}
.sync-dot.err{background:#ef4444;}
</style>

<?php
/* ─── PHP HELPER: render card HTML ────────────────────────── */
function renderCard(array $card, int $listId, array $CODE_TEN, array $OTHER_TAGS): string {
    $id     = $card['id'];
    $prio   = $card['priority'] ?? 'normale';
    $title  = htmlspecialchars($card['title']);
    $hasCover = !empty($card['cover_url']);
    $totalItems = (int)($card['total_items'] ?? 0);
    $doneItems  = (int)($card['done_items'] ?? 0);
    $pct   = $totalItems ? round($doneItems/$totalItems*100) : 0;
    $cardColor = !empty($card['card_color']) ? htmlspecialchars($card['card_color']) : '';
    $desc  = !empty($card['description']) ? htmlspecialchars(mb_substr($card['description'],0,120)) : '';

    /* due badge */
    $dueBadge = '';
    if (!empty($card['due_date'])) {
        $due = strtotime($card['due_date']); $now = time(); $diff = $due-$now;
        if ($diff < 0)         $dueBadge = '<span class="cfoot-badge ov"><i class="fas fa-clock"></i> Retard</span>';
        elseif ($diff < 7200)  $dueBadge = '<span class="cfoot-badge ds"><i class="fas fa-clock"></i> '.date('H:i',$due).'</span>';
        else                   $dueBadge = '<span class="cfoot-badge"><i class="fas fa-clock"></i> '.date('d/m H:i',$due).'</span>';
    }

    /* grade tags */
    $gradeTags = '';
    foreach ($card['grade_tags'] as $g)
        $gradeTags .= '<span class="ctag ct-grade"><i class="fas fa-shield-alt" style="font-size:.5rem"></i> '.htmlspecialchars($g).'</span>';

    /* vehicle + plate */
    $vehicleTag = '';
    if (!empty($card['patrol_vehicle'])) {
        $plateLbl = !empty($card['vehicle_plate']) ? ' · '.htmlspecialchars($card['vehicle_plate']) : '';
        $vehicleTag = '<span class="ctag ct-veh"><i class="fas fa-car" style="font-size:.5rem"></i> '.htmlspecialchars($card['patrol_vehicle']).$plateLbl.'</span>';
    }

    /* code 10 */
    $tenTags = '';
    foreach ($card['code_ten_tags'] as $t) {
        $cls = 'ct-'.($CODE_TEN[$t] ?? 'gray');
        $tenTags .= '<span class="ctag '.$cls.'">📡 '.$t.'</span>';
    }

    /* other tags */
    $otherTags = '';
    foreach ($card['other_tags'] as $t) {
        $cls = 'ct-'.($OTHER_TAGS[$t] ?? 'gray');
        $otherTags .= '<span class="ctag '.$cls.'">'.htmlspecialchars($t).'</span>';
    }

    /* labels */
    $labelsHtml = '';
    if (!empty($card['labels'])) {
        $labelsHtml = '<div class="card-lbls">';
        foreach ($card['labels'] as $l)
            $labelsHtml .= '<span class="card-lbl" style="background:'.htmlspecialchars($l['color']).'">'.htmlspecialchars($l['name']).'</span>';
        $labelsHtml .= '</div>';
    }

    $hasTags = $gradeTags || $vehicleTag || $tenTags || $otherTags;

    /* card style */
    $styleAttr = '';
    $dataBg = '';
    if ($cardColor) {
        // parse hex -> apply as dark-tinted background
        $styleAttr = ' style="background:'.hsl_darken($cardColor).';border-color:'.hex_alpha($cardColor, .35).';"';
        $dataBg = ' data-bg="'.$cardColor.'"';
    }

    $html  = '<div class="dcard" id="card-'.$id.'" data-card-id="'.$id.'" data-p="'.$prio.'"'
           . ' data-title="'.strtolower($title).'"'
           . $dataBg
           . ' onclick="openCard('.$id.')"'
           . $styleAttr.'>';

    /* cover image en fond de carte */
    if ($hasCover) {
        $html .= '<div class="card-cover-wrap">';
        $html .= '<img class="card-cover" src="'.htmlspecialchars($card['cover_url']).'" alt="" onclick="event.stopPropagation();openLbx(\''.htmlspecialchars($card['cover_url']).'\')" title="Agrandir">';
        $html .= '<div class="card-cover-ttl">'.$title.'</div>';
        $html .= '<span class="card-zoom-btn" onclick="event.stopPropagation();openLbx(\''.htmlspecialchars($card['cover_url']).'\')" title="Voir en grand"><i class="fas fa-expand-alt"></i></span>';
        $html .= '</div>';
    }

    $html .= '<div class="card-body">';
    if ($labelsHtml) $html .= $labelsHtml;
    if (!$hasCover) $html .= '<div class="card-title">'.$title.'</div>';

    if ($desc) {
        $short = mb_strlen($card['description']) > 120 ? $desc.'…' : $desc;
        $html .= '<div class="card-desc-prev">'.$short.'</div>';
    }

    if ($hasTags) {
        $html .= '<div class="card-tags">'.$gradeTags.$vehicleTag.$tenTags.$otherTags.'</div>';
    }

    /* footer row */
    $html .= '<div class="card-foot">';
    if ($prio) {
        $html .= '<span class="prio-lbl '.$prio.'">'.strtoupper($prio).'</span>';
    }
    $html .= $dueBadge;
    if (!empty($card['assigned_agents']) && is_array($card['assigned_agents'])) {
        $av=''; $names=[];
        foreach (array_slice($card['assigned_agents'],0,5) as $a) {
            $nm=trim(($a['name']??'').($a['matricule']?' #'.$a['matricule']:'')); $names[]=$nm;
            $av .= !empty($a['avatar'])
                ? '<img src="'.htmlspecialchars($a['avatar']).'" title="'.htmlspecialchars($nm).'" style="width:22px;height:22px;border-radius:50%;object-fit:cover;border:2px solid var(--s1);margin-left:-6px">'
                : '<span title="'.htmlspecialchars($nm).'" style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:inline-grid;place-items:center;color:#fff;font-size:.6rem;font-weight:700;border:2px solid var(--s1);margin-left:-6px">'.htmlspecialchars(mb_strtoupper(mb_substr($a['name']??'?',0,1))).'</span>';
        }
        $more=count($card['assigned_agents'])>5?' +'.(count($card['assigned_agents'])-5):'';
        $html .= '<span class="cfoot-badge" title="'.htmlspecialchars(implode(', ',$names)).'" style="padding-left:6px"><span style="display:inline-flex;align-items:center;padding-left:6px">'.$av.'</span>'.$more.'</span>';
    } elseif (!empty($card['assigned_to'])) {
        $html .= '<span class="cfoot-badge"><i class="fas fa-user"></i> '.htmlspecialchars($card['assigned_to']).'</span>';
    }
    if (!empty($card['location']))
        $html .= '<span class="cfoot-badge"><i class="fas fa-map-marker-alt"></i> '.htmlspecialchars($card['location']).'</span>';
    $html .= '</div>';

    /* checklist mini */
    if ($totalItems > 0) {
        $html .= '<div class="cl-mini"><div class="cl-bar"><div class="cl-fill" style="width:'.$pct.'%"></div></div><span>'.$doneItems.'/'.$totalItems.'</span></div>';
    }

    $html .= '</div></div>';
    return $html;
}

/* helpers couleur */
function hex_alpha(string $hex, float $alpha): string {
    $hex = ltrim($hex,'#');
    if(strlen($hex)===3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r=hexdec(substr($hex,0,2)); $g=hexdec(substr($hex,2,2)); $b=hexdec(substr($hex,4,2));
    return "rgba($r,$g,$b,$alpha)";
}
function hsl_darken(string $hex): string {
    // Applique la couleur en fond avec forte opacité sombre
    return hex_alpha($hex, 0.18);
}
?>

<!-- ===== BOARD ===== -->
<div class="db-outer">

    <!-- TOPBAR -->
    <div class="db-bar">
        <div class="db-logo"><i class="fas fa-broadcast-tower"></i> <?php echo htmlspecialchars($DISPATCH_THEME['board_title'] ?: 'DISPATCH BOARD'); ?></div>
        <div class="db-search">
            <input type="text" id="searchIn" placeholder="Rechercher..." oninput="doFilter()">
        </div>
        <div class="db-filters">
            <button class="fb on" onclick="setPrio(null,this)">Tout</button>
            <button class="fb" style="color:var(--crit)" onclick="setPrio('critique',this)">⬤ Critique</button>
            <button class="fb" style="color:var(--haut)" onclick="setPrio('haute',this)">⬤ Haute</button>
            <button class="fb" style="color:var(--norm)" onclick="setPrio('normale',this)">⬤ Normale</button>
            <button class="fb" style="color:var(--faib)" onclick="setPrio('faible',this)">⬤ Faible</button>
        </div>
        <div class="db-acts">
            <button class="btn btn-p" onclick="openLabelMgr()"><i class="fas fa-tags"></i> Labels</button>
            <?php if ($isAdmin): ?>
            <button class="btn btn-p" onclick="openMgr('vehicle')"><i class="fas fa-car"></i> Véhicules</button>
            <button class="btn btn-p" onclick="openMgr('grade')"><i class="fas fa-shield-alt"></i> Grades</button>
            <?php endif; ?>
            <button class="btn btn-p" onclick="event.stopPropagation();openAddList()"><i class="fas fa-plus"></i> Colonne</button>
            <span class="sync-dot" id="syncDot" title="Synchronisation temps réel"></span>
        </div>
    </div>

    <!-- BOARD -->
    <div class="db-scroll" id="boardScroll">

        <?php foreach ($lists as $list): ?>
        <?php $lc = htmlspecialchars($list['color']); ?>
        <div class="list-col" id="col-<?=$list['id']?>" data-lid="<?=$list['id']?>">

            <div class="col-hdr" style="border-color:<?=$lc?>33;">
                <div class="col-dot" style="background:<?=$lc?>;box-shadow:0 0 7px <?=$lc?>66;"></div>
                <input class="col-name" type="text"
                       value="<?=htmlspecialchars($list['name'])?>"
                       onchange="saveListName(<?=$list['id']?>,this.value,'<?=$lc?>')"
                       onclick="event.stopPropagation()"
                       onmousedown="event.stopPropagation()">
                <span class="col-cnt" id="cnt-<?=$list['id']?>"><?=count($list['cards'])?></span>
                <button class="col-more" onclick="openCtx(event,<?=$list['id']?>)">
                    <i class="fas fa-ellipsis-h"></i>
                </button>
            </div>

            <div class="cards-wrap" id="cw-<?=$list['id']?>">

                <?php foreach ($list['cards'] as $card): ?>
                    <?= renderCard($card, $list['id'], $CODE_TEN, $OTHER_TAGS) ?>
                <?php endforeach; ?>

            </div>

            <div id="acf-<?=$list['id']?>" class="add-card-form">
                <textarea placeholder="Titre de la carte..." rows="3"
                          onkeydown="acfKey(event,<?=$list['id']?>)"></textarea>
                <div class="acf-row">
                    <button class="btn btn-p btn-sm" onclick="doAddCard(<?=$list['id']?>)"><i class="fas fa-plus"></i> Ajouter</button>
                    <button class="btn btn-g btn-sm" onclick="cancelAcf(<?=$list['id']?>)"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <button class="add-card-row" id="acb-<?=$list['id']?>" onclick="showAcf(<?=$list['id']?>)">
                <i class="fas fa-plus"></i> Ajouter une carte
            </button>

        </div>
        <?php endforeach; ?>

        <button class="add-list-btn" onclick="event.stopPropagation();openAddList()"><i class="fas fa-plus"></i> Ajouter une colonne</button>

    </div>
</div>

<!-- ===== CARD MODAL ===== -->
<div class="mov hidden" id="cardModal" onclick="if(event.target===this)saveAndClose()">
<div class="mbox">

    <div class="m-top">
        <div class="m-ico"><i class="fas fa-ticket-alt"></i></div>
        <textarea class="m-title-inp" id="mTitle" rows="1" oninput="autoH(this)"></textarea>
        <button class="m-x" onclick="saveAndClose()"><i class="fas fa-times"></i></button>
    </div>
    <div class="m-sub">Dans : <strong id="mListName"></strong></div>

    <div class="m-body">
    <div class="m-main">

        <!-- COVER -->
        <div>
            <div class="m-label"><i class="fas fa-image"></i> Image de couverture</div>
            <div id="coverPreviewWrap" style="display:none;">
                <div class="cover-preview-wrap">
                    <img id="coverPreviewImg" class="cover-preview" src="" alt="" style="cursor:zoom-in;" onclick="openLbx(this.src)" title="Cliquer pour agrandir">
                    <div style="position:absolute;top:5px;right:5px;display:flex;gap:4px;">
                        <button class="btn btn-sm" style="background:rgba(0,0,0,.55);border:1px solid rgba(255,255,255,.2);color:#fff;padding:4px 8px;" onclick="openLbx(document.getElementById('coverPreviewImg').src)" title="Voir en grand"><i class="fas fa-expand-alt"></i></button>
                        <button class="btn btn-r btn-sm" onclick="removeCover()"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            </div>
            <div class="cover-drop" id="coverDrop" onclick="document.getElementById('coverInput').click()" ondragover="event.preventDefault()" ondrop="handleCoverDrop(event)">
                <input type="file" id="coverInput" accept="image/*" onchange="uploadCover(this)">
                <i class="fas fa-cloud-upload-alt"></i>
                <p>Cliquer ou déposer une image (max 5 MB)</p>
            </div>
            <button type="button" class="btn btn-sm" id="coverCdnBtn" style="margin-top:.5rem;width:100%;background:rgba(0,229,255,.1);border:1px solid rgba(0,229,255,.3);color:#7de9ff;justify-content:center" onclick="openCoverCdn()"><i class="fas fa-images"></i> Choisir depuis le CDN LSPD</button>
        </div>

        <!-- PRIORITY / ASSIGNED / LOCATION / DUE -->
        <div>
            <div class="m-label"><i class="fas fa-exclamation-circle"></i> Détails</div>
            <div style="margin-bottom:.45rem;">
                <div class="m-label" style="margin-bottom:.32rem;font-size:.55rem;">PRIORITÉ</div>
                <div class="prio-btns" id="prioBtns">
                    <button type="button" class="prio-btn pb-none on" data-p="" onclick="setPrioBtn(this)">— Aucune</button>
                    <button type="button" class="prio-btn pb-faible" data-p="faible" onclick="setPrioBtn(this)">🟢 Faible</button>
                    <button type="button" class="prio-btn pb-normale" data-p="normale" onclick="setPrioBtn(this)">🔵 Normale</button>
                    <button type="button" class="prio-btn pb-haute" data-p="haute" onclick="setPrioBtn(this)">🟡 Haute</button>
                    <button type="button" class="prio-btn pb-critique" data-p="critique" onclick="setPrioBtn(this)">🔴 Critique</button>
                </div>
                <input type="hidden" id="mPrio" value="">
            </div>
            <div style="margin-bottom:.45rem;">
                <div class="m-label" style="margin-bottom:.32rem;font-size:.55rem;">OFFICIERS ASSIGNÉS</div>
                <div id="mAgentsChips" style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.4rem;"></div>
                <input type="hidden" id="mAssigned">
                <input type="hidden" id="mAgents" value="[]">
                <button type="button" class="btn btn-sm" style="width:100%;justify-content:center;background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.3);color:#93c5fd;" onclick="openOfficerPicker()"><i class="fas fa-user-shield"></i> Sélectionner les officiers en service</button>
            </div>
            <div style="margin-bottom:.45rem;">
                <input class="minp" id="mLocation" placeholder="📍 Localisation..." style="width:100%;">
            </div>
            <div>
                <input class="minp" type="datetime-local" id="mDue" style="color-scheme:dark;width:100%;">
            </div>
        </div>

        <!-- COULEUR DE CARTE -->
        <div>
            <div class="m-label"><i class="fas fa-palette"></i> Couleur de la carte</div>
            <div class="card-color-row" id="cardColorRow">
                <div class="cc-swatch none on" data-color="" onclick="setCardColor('')" title="Défaut">✕</div>
                <div class="cc-swatch" data-color="#ef4444" style="background:rgba(239,68,68,.25);border-color:rgba(239,68,68,.4);" onclick="setCardColor('#ef4444')" title="Rouge"></div>
                <div class="cc-swatch" data-color="#f97316" style="background:rgba(249,115,22,.25);border-color:rgba(249,115,22,.4);" onclick="setCardColor('#f97316')" title="Orange"></div>
                <div class="cc-swatch" data-color="#f59e0b" style="background:rgba(245,158,11,.25);border-color:rgba(245,158,11,.4);" onclick="setCardColor('#f59e0b')" title="Jaune"></div>
                <div class="cc-swatch" data-color="#22c55e" style="background:rgba(34,197,94,.25);border-color:rgba(34,197,94,.4);" onclick="setCardColor('#22c55e')" title="Vert"></div>
                <div class="cc-swatch" data-color="#00e5ff" style="background:rgba(0,229,255,.2);border-color:rgba(0,229,255,.4);" onclick="setCardColor('#00e5ff')" title="Cyan"></div>
                <div class="cc-swatch" data-color="#3b82f6" style="background:rgba(59,130,246,.25);border-color:rgba(59,130,246,.4);" onclick="setCardColor('#3b82f6')" title="Bleu"></div>
                <div class="cc-swatch" data-color="#8b5cf6" style="background:rgba(139,92,246,.25);border-color:rgba(139,92,246,.4);" onclick="setCardColor('#8b5cf6')" title="Violet"></div>
                <div class="cc-swatch" data-color="#ec4899" style="background:rgba(236,72,153,.25);border-color:rgba(236,72,153,.4);" onclick="setCardColor('#ec4899')" title="Rose"></div>
                <div class="cc-swatch" data-color="#64748b" style="background:rgba(100,116,139,.25);border-color:rgba(100,116,139,.4);" onclick="setCardColor('#64748b')" title="Gris"></div>
                <button class="cc-custom-btn" onclick="openColorPicker('card')"><i class="fas fa-eyedropper"></i> Perso</button>
            </div>
        </div>

        <!-- GRADES (auto depuis les agents) -->
        <div>
            <div class="m-label"><i class="fas fa-shield-alt"></i> Spécialités <span style="font-size:.55rem;color:var(--mu);text-transform:none;letter-spacing:0;font-weight:400;">— détectées automatiquement depuis les divisions des agents</span></div>
            <div class="grade-grid" id="mGrades" style="display:flex;flex-wrap:wrap;gap:.4rem;min-height:24px;"></div>
            <div id="mGradesEmpty" style="font-size:.72rem;color:var(--mu);font-style:italic;">Aucune spécialité — assignez des officiers en service.</div>
        </div>

        <!-- VEHICLE + PLATE -->
        <div>
            <div class="m-label"><i class="fas fa-car"></i> Véhicule de patrouille</div>
            <select class="msel" id="mVehicle" onchange="onVehicleChange()">
                <option value="">— Aucun —</option>
                <?php foreach ($patrolVehicles as $v): ?>
                <option value="<?=htmlspecialchars($v['name'])?>" data-vid="<?=(int)$v['id']?>"><?=htmlspecialchars($v['name'])?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" id="mPlate" value="">
            <div class="plate-block hidden" id="plateBlock">
                <div class="plate-head">
                    <div class="plate-head-l">
                        <i class="fas fa-id-card-alt"></i>
                        <span>Plaque sélectionnée :</span>
                        <span class="plate-current" id="plateCurrent">— Aucune —</span>
                    </div>
                    <button type="button" class="plate-clear hidden" id="plateClearBtn" onclick="clearPlate()" title="Désélectionner la plaque"><i class="fas fa-xmark"></i></button>
                </div>
                <div class="plate-grid" id="plateGrid">
                    <div class="plate-empty">Chargement…</div>
                </div>
                <div class="plate-legend">
                    <span><span class="plate-dot ok"></span> Disponible</span>
                    <span><span class="plate-dot busy"></span> Utilisée par une autre carte</span>
                </div>
            </div>
        </div>

        <!-- CODE TEN -->
        <div>
            <div class="m-label"><i class="fas fa-radio"></i> Code Dix</div>
            <button type="button" class="btn btn-sm" style="width:100%;justify-content:center;background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.3);color:#93c5fd;margin-bottom:.4rem;" onclick="openTagPicker('ten')"><i class="fas fa-tower-broadcast"></i> Sélectionner un Code Ten</button>
            <div class="tag-dd" id="tenDD" style="display:none;">
                <div class="tag-trigger" id="tenTrig" onclick="toggleDD('tenDD')">
                    <span>Sélectionner des codes...</span>
                    <i class="fas fa-chevron-down" style="font-size:.65rem;color:var(--mu);"></i>
                </div>
                <div class="tag-panel" id="tenPanel">
                    <input class="tag-srch" placeholder="Rechercher un code..." oninput="filterTagOpts('tenPanel', this.value)">
                    <div class="tag-opts" id="tenOpts">
                        <?php foreach ($CODE_TEN as $code => $color): ?>
                        <label class="tag-opt">
                            <input type="checkbox" value="<?=$code?>" onchange="updateTagSel('ten')">
                            <span class="tag-opt-lbl">
                                <span class="tag-dot" style="background:var(--ct-<?=$color?>-dot, <?= match($color){
                                    'gray'=>'#94a3b8','green'=>'#22c55e','red'=>'#ef4444',
                                    'orange'=>'#f97316','blue'=>'#60a5fa',default=>'#94a3b8'
                                } ?>);"></span>
                                📡 <?=$code?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="sel-tags" id="tenSelTags"></div>
        </div>

        <!-- OTHER TAGS -->
        <div>
            <div class="m-label"><i class="fas fa-tag"></i> Tags statut</div>
            <button type="button" class="btn btn-sm" style="width:100%;justify-content:center;background:rgba(167,139,250,.12);border:1px solid rgba(167,139,250,.3);color:#c4b5fd;margin-bottom:.4rem;" onclick="openTagPicker('other')"><i class="fas fa-tag"></i> Sélectionner un statut</button>
            <div class="tag-dd" id="otherDD" style="display:none;">
                <div class="tag-trigger" id="otherTrig" onclick="toggleDD('otherDD')">
                    <span>Sélectionner un statut...</span>
                    <i class="fas fa-chevron-down" style="font-size:.65rem;color:var(--mu);"></i>
                </div>
                <div class="tag-panel" id="otherPanel">
                    <input class="tag-srch" placeholder="Rechercher..." oninput="filterTagOpts('otherPanel', this.value)">
                    <div class="tag-opts" id="otherOpts">
                        <?php foreach ($OTHER_TAGS as $tag => $color): ?>
                        <?php $dot = match($color){'gray'=>'#94a3b8','green'=>'#22c55e','red'=>'#ef4444','orange'=>'#f97316','purple'=>'#a78bfa','yellow'=>'#eab308',default=>'#94a3b8'}; ?>
                        <label class="tag-opt">
                            <input type="checkbox" value="<?=htmlspecialchars($tag)?>" onchange="updateTagSel('other')">
                            <span class="tag-opt-lbl">
                                <span class="tag-dot" style="background:<?=$dot?>;"></span>
                                <?=htmlspecialchars($tag)?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="sel-tags" id="otherSelTags"></div>
        </div>

        <!-- LABELS -->
        <div>
            <div class="m-label"><i class="fas fa-tags"></i> Labels</div>
            <div class="lbl-grid" id="mLabels"></div>
        </div>

        <!-- DESCRIPTION -->
        <div>
            <div class="m-label"><i class="fas fa-align-left"></i> Description</div>
            <textarea class="desc-ta" id="mDesc" placeholder="Ajouter une description..."></textarea>
        </div>

        <!-- CHECKLISTS -->
        <div>
            <div class="m-label"><i class="fas fa-tasks"></i> Checklists</div>
            <div id="mChecklists"></div>
        </div>

        <!-- COMMENTS -->
        <div>
            <div class="m-label"><i class="fas fa-comments"></i> Commentaires</div>
            <div id="mComments"></div>
            <div class="cmt-new">
                <div class="cmt-av" id="myAv"></div>
                <textarea class="cmt-ta" id="cmtIn" placeholder="Écrire un commentaire..." rows="2"
                          onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();submitCmt();}"></textarea>
                <button class="btn btn-p btn-sm" onclick="submitCmt()"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>

    </div><!-- /m-main -->
    <div class="m-side">
        <div class="s-lbl">Actions</div>
        <button class="sbt" onclick="saveCard()"><i class="fas fa-save"></i> Enregistrer</button>
        <button class="sbt" onclick="addCL()"><i class="fas fa-tasks"></i> Checklist</button>
        <button class="sbt cl-global-tog" id="clGlobalTog" onclick="toggleCardUseGlobal()" title="Activer / désactiver l'utilisation des checklists globales">
            <i class="fas fa-globe"></i> Checklist Global
        </button>
        <button class="sbt" onclick="openGclMgr()" title="Gérer les checklists globales">
            <i class="fas fa-cog"></i> Gérer globales
        </button>
        <div class="m-sep"></div>
        <div class="s-lbl">Danger</div>
        <button class="sbt dng" onclick="deleteCard()"><i class="fas fa-trash"></i> Supprimer</button>
        <div class="m-sep"></div>
        <div class="s-lbl" style="display:flex;align-items:center;justify-content:space-between"><span><i class="fas fa-clock-rotate-left" style="margin-right:.3rem"></i> Historique</span><button onclick="loadCardHistory()" title="Rafraîchir" style="background:none;border:none;color:var(--mu);cursor:pointer"><i class="fas fa-rotate"></i></button></div>
        <div id="cardHistory" style="max-height:300px;overflow-y:auto;font-size:.74rem"></div>
    </div>
    </div><!-- /m-body -->

</div>
</div>

<!-- ===== LABEL MANAGER MODAL ===== -->
<div class="mov hidden" id="lblModal" onclick="if(event.target===this)closeLblMgr()">
<div class="mbox" style="max-width:460px;">
    <div class="m-top" style="padding:1.1rem;">
        <div class="m-ico"><i class="fas fa-tags"></i></div>
        <h3 style="font-family:'Orbitron',monospace;font-size:.82rem;font-weight:700;color:var(--tx);letter-spacing:1px;margin:0;">LABELS</h3>
        <button class="m-x" onclick="closeLblMgr()"><i class="fas fa-times"></i></button>
    </div>
    <div style="padding:.85rem 1.1rem 1.1rem;">
        <div class="mgr-list" id="lblList"></div>
        <div style="background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.07);border-radius:9px;padding:.75rem;">
            <div class="m-label" style="margin-bottom:.35rem;"><i class="fas fa-plus"></i> Nouveau label</div>
            <input type="text" id="newLblName" class="minp" placeholder="Nom..." style="margin-bottom:.45rem;">
            <div class="alm-color-trigger" onclick="openColorPicker('label')" style="margin-bottom:.5rem;">
                <div class="alm-color-swatch" id="lblSwatch" style="background:#00e5ff;width:28px;height:28px;border-radius:6px;flex-shrink:0;"></div>
                <span class="alm-color-label" id="lblColorLabel">#00E5FF</span>
                <i class="fas fa-chevron-right alm-color-arrow"></i>
            </div>
            <button class="btn btn-p" style="width:100%;" onclick="createLabel()"><i class="fas fa-plus"></i> Créer</button>
        </div>
    </div>
</div>
</div>

<!-- ===== GLOBAL CHECKLISTS MANAGER MODAL ===== -->
<div class="mov hidden" id="gclModal" onclick="if(event.target===this)closeGclMgr()">
<div class="mbox" style="max-width:560px;">
    <div class="m-top" style="padding:1.1rem;">
        <div class="m-ico" style="background:rgba(249,115,22,.18);color:#fb923c;"><i class="fas fa-globe"></i></div>
        <h3 style="font-family:'Orbitron',monospace;font-size:.82rem;font-weight:700;color:var(--tx);letter-spacing:1px;margin:0;">CHECKLISTS GLOBALES</h3>
        <button class="m-x" onclick="closeGclMgr()"><i class="fas fa-times"></i></button>
    </div>
    <div style="padding:.85rem 1.1rem 1.1rem; max-height:65vh; overflow-y:auto;">
        <p style="font-family:'Chakra Petch',sans-serif;font-size:.74rem;color:var(--mu);margin:0 0 .8rem;line-height:1.45;">
            Les checklists globales s'appliquent automatiquement à toutes les cartes ayant l'option <strong style="color:#fb923c;">Checklist Global</strong> activée.
            Une suppression est immédiate pour toutes les cartes.
        </p>
        <div id="gclList"></div>
        <div style="background:rgba(249,115,22,.04);border:1px solid rgba(249,115,22,.18);border-radius:9px;padding:.75rem;margin-top:.7rem;">
            <div class="m-label" style="margin-bottom:.35rem;color:#fb923c;"><i class="fas fa-plus"></i> Nouvelle checklist globale</div>
            <div style="display:flex;gap:.4rem;">
                <input type="text" id="newGclTitle" class="minp" placeholder="Titre (ex: Vérifications obligatoires)" onkeydown="if(event.key==='Enter')createGcl()">
                <button class="btn btn-p btn-sm" style="background:linear-gradient(135deg,rgba(249,115,22,.25),rgba(245,101,16,.25));border-color:rgba(249,115,22,.45);color:#fb923c;" onclick="createGcl()"><i class="fas fa-plus"></i> Créer</button>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ===== MANAGE MODAL (vehicles / grades) ===== -->
<div class="mov hidden" id="mgrModal" onclick="if(event.target===this)closeMgr()">
<div class="mbox" style="max-width:420px;">
    <div class="m-top" style="padding:1.1rem;">
        <div class="m-ico" id="mgrIco"><i class="fas fa-car"></i></div>
        <h3 style="font-family:'Orbitron',monospace;font-size:.82rem;font-weight:700;color:var(--tx);letter-spacing:1px;margin:0;" id="mgrTitle">VÉHICULES</h3>
        <button class="m-x" onclick="closeMgr()"><i class="fas fa-times"></i></button>
    </div>
    <div style="padding:.85rem 1.1rem 1.1rem;">
        <div class="mgr-list" id="mgrList"></div>
        <div style="display:flex;gap:.4rem;">
            <input type="text" id="mgrNewName" class="minp" placeholder="Nouveau nom...">
            <button class="btn btn-p btn-sm" onclick="mgrAdd()"><i class="fas fa-plus"></i></button>
        </div>
    </div>
</div>
</div>

<!-- ===== ADD LIST MODAL ===== -->
<div class="alm-ov hidden" id="addListOv" onclick="if(event.target===this)closeAddList()">
<div class="alm-box" onclick="event.stopPropagation()">
    <div class="alm-head">
        <div class="alm-ttl"><i class="fas fa-columns"></i> Nouvelle colonne</div>
        <button class="m-x" onclick="closeAddList()"><i class="fas fa-times"></i></button>
    </div>
    <label class="alm-f-lbl">Nom de la colonne</label>
    <input type="text" class="alm-name-inp" id="newColName" placeholder="Ex: En route..." onkeydown="if(event.key==='Enter')doAddList()">
    <label class="alm-f-lbl">Couleur</label>
    <div class="alm-color-trigger" onclick="openColorPicker('colonne')">
        <div class="alm-color-swatch" id="almSwatch" style="background:#00e5ff;"></div>
        <span class="alm-color-label" id="almColorLabel">#00E5FF</span>
        <i class="fas fa-chevron-right alm-color-arrow"></i>
    </div>
    <div class="alm-btns">
        <button class="btn btn-p" style="flex:1;" onclick="doAddList()"><i class="fas fa-plus"></i> Créer la colonne</button>
        <button class="btn btn-g" onclick="closeAddList()"><i class="fas fa-times"></i></button>
    </div>
</div>
</div>

<!-- ===== COLOR PICKER MODAL ===== -->
<div class="cpk-ov hidden" id="cpkOv" onclick="if(event.target===this)closeCpk()">
<div class="cpk-box">
    <div class="cpk-head">
        <div class="cpk-ttl"><i class="fas fa-palette"></i> Couleur</div>
        <button class="m-x" onclick="closeCpk()"><i class="fas fa-times"></i></button>
    </div>
    <!-- SV canvas -->
    <div class="cpk-sv-wrap" id="cpkSVWrap">
        <canvas id="cpkSV" width="280" height="165"></canvas>
        <div class="cpk-sv-cur" id="cpkSVCur" style="left:100%;top:0%;"></div>
    </div>
    <!-- Hue -->
    <div class="cpk-hue-wrap" id="cpkHueWrap">
        <div class="cpk-hue-thumb" id="cpkHueThumb" style="left:0%;background:#f00;"></div>
    </div>
    <!-- Presets -->
    <div class="cpk-presets" id="cpkPresets"></div>
    <!-- Hex + preview -->
    <div class="cpk-hex-row">
        <div class="cpk-preview" id="cpkPreview" style="background:#00e5ff;"></div>
        <input type="text" class="cpk-hex" id="cpkHex" value="#00E5FF" maxlength="7" oninput="cpkHexInput(this.value)" onkeydown="if(event.key==='Enter')cpkConfirm()">
    </div>
    <div class="cpk-btns">
        <button class="btn btn-p" style="flex:1;" onclick="cpkConfirm()"><i class="fas fa-check"></i> Appliquer</button>
        <button class="btn btn-g" onclick="closeCpk()">Annuler</button>
    </div>
</div>
</div>

<!-- ===== LIGHTBOX ===== -->
<div class="lbx-ov hidden" id="lbxOv" onclick="closeLbx()">
    <button class="lbx-close" onclick="closeLbx()"><i class="fas fa-times"></i></button>
    <img class="lbx-img" id="lbxImg" src="" alt="" onclick="event.stopPropagation()">
</div>

<!-- ===== CTX MENU ===== -->
<div class="ctx hidden" id="ctxMenu">
    <div class="ctx-i" onclick="ctxColor()"><i class="fas fa-palette"></i> Changer couleur</div>
    <div class="ctx-i" onclick="ctxClear()"><i class="fas fa-eraser"></i> Vider la colonne</div>
    <div class="ctx-i dng" onclick="ctxDel()"><i class="fas fa-trash"></i> Supprimer</div>
</div>

<!-- ===== DELETE CARD CONFIRM MODAL ===== -->
<div class="del-ov hidden" id="delCardOv">
<div class="del-box">
    <div class="del-ico"><i class="fas fa-trash-alt"></i></div>
    <div class="del-title">Supprimer la carte ?</div>
    <div class="del-sub">Cette action est irréversible. La carte et toutes ses données (checklists, commentaires, images) seront définitivement supprimées.</div>
    <div class="del-btns">
        <button class="btn btn-g del-cancel" onclick="closeDelModal()"><i class="fas fa-times"></i> Annuler</button>
        <button class="btn del-confirm" onclick="confirmDeleteCard()"><i class="fas fa-trash-alt"></i> Supprimer</button>
    </div>
</div>
</div>

<!-- ===== TOASTS ===== -->
<div class="toast-c" id="toastC"></div>

<!-- ===== JS ===== -->
<script>
/* ────────── CONSTANTS ────────── */
const ME = '<?=addslashes($author)?>';
const IS_ADMIN = <?=$isAdmin?'true':'false'?>;
const CODE_TEN_COLORS = <?=json_encode($CODE_TEN)?>;
const OTHER_TAG_COLORS = <?=json_encode($OTHER_TAGS)?>;
const TAG_CSS = { gray:'ct-gray', green:'ct-green', red:'ct-red', orange:'ct-orange', blue:'ct-blue', purple:'ct-purple', yellow:'ct-yellow' };

let curCard = null;
let curListId = null;
let colColor = '#00e5ff';
let lblColor = '#00e5ff';
let cardColor = ''; // couleur courante de la carte en cours d'édition
let mgrMode = 'vehicle';
let activePrio = null;
let lblsData = <?=json_encode($allLabels)?>;
let vehiclesData = <?=json_encode(array_values($patrolVehicles))?>;
let gradesData = <?=json_encode(array_values($grades))?>;

// Initialize color cache from server data
let currentColColors = <?php
    $colorMap = [];
    foreach ($lists as $l) { $colorMap[$l['id']] = $l['color'] ?: '#00e5ff'; }
    echo json_encode($colorMap);
?>;

/* ─── API ─── */
async function api(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    const r = await fetch(location.href+'?ajax=1', {method:'POST', body:fd});
    return r.json();
}
async function apiGet(p) {
    const r = await fetch(location.href+'?ajax=1&'+new URLSearchParams(p));
    return r.json();
}

/* ─── TOAST ─── */
function toast(msg, t='s') {
    const el = document.createElement('div');
    el.className = `toast t${t}`;
    el.innerHTML = `<i class="fas fa-${t==='s'?'check-circle':t==='e'?'exclamation-circle':'info-circle'}"></i> ${msg}`;
    document.getElementById('toastC').appendChild(el);
    setTimeout(()=>{ el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(()=>el.remove(),300); }, 3000);
}

/* ─── ESC ─── */
document.addEventListener('keydown', e => {
    if (e.key==='Escape') { closeLbx(); closeDelModal(); closeGclMgr(); saveAndClose(); closeLblMgr(); closeMgr(); closeAddList(); closeCtxMenu(); closeCpk(); }
});

/* ─── CLICK OUTSIDE ─── */
document.addEventListener('click', e => {
    if (!document.getElementById('ctxMenu').contains(e.target)) closeCtxMenu();
    // close tag dropdowns
    ['tenDD','otherDD'].forEach(id => {
        const dd = document.getElementById(id);
        if (dd && !dd.contains(e.target)) {
            dd.querySelector('.tag-panel').classList.remove('open');
            dd.querySelector('.tag-trigger').classList.remove('open');
        }
    });
});

/* ─── FILTER ─── */
function doFilter() {
    const q = document.getElementById('searchIn').value.toLowerCase().trim();
    document.querySelectorAll('.dcard').forEach(c => {
        const t = c.dataset.title || '';
        const p = c.dataset.p || '';
        let show = (!q || t.includes(q));
        if (activePrio) show = show && p === activePrio;
        c.classList.toggle('fout', !show);
    });
}
function setPrio(p, btn) {
    activePrio = p;
    document.querySelectorAll('.fb').forEach(b=>b.classList.remove('on'));
    btn.classList.add('on');
    doFilter();
}

/* ─── DRAG & DROP (mouse events — compatibles FiveM CEF) ─── */
/*
 * Le CEF de FiveM ne supporte pas l'API HTML5 Drag & Drop.
 * On utilise mousedown/mousemove/mouseup avec un "ghost" cloné.
 */
let drag = {
    active: false, type: null,
    el: null, ghost: null,
    id: null, fromLid: null,
    startX: 0, startY: 0,
    offX: 0, offY: 0
};

/* --- init : écoute globale --- */
function initDrag() {
    document.addEventListener('mousedown', onDragStart, true);
    document.addEventListener('mousemove', onDragMove);
    document.addEventListener('mouseup',   onDragEnd);
}

function onDragStart(e) {
    if (e.button !== 0) return;

    /* ── CARTE ── */
    const card = e.target.closest('.dcard');
    if (card && !e.target.closest('.card-zoom-btn') && !e.target.closest('.g-rm')
            && !e.target.closest('input') && !e.target.closest('button')
            && !e.target.closest('.card-img-badge')) {
        const lid = card.closest('.cards-wrap')?.id.replace('cw-','');
        drag.type   = 'card';
        drag.el     = card;
        drag.id     = card.dataset.cardId;
        drag.fromLid= lid;
        _startDrag(e, card);
        return;
    }

    /* ── COLONNE (header seulement) ── */
    const hdr = e.target.closest('.col-hdr');
    if (hdr && !e.target.closest('input') && !e.target.closest('button')) {
        const col = hdr.closest('.list-col');
        drag.type = 'col';
        drag.el   = col;
        drag.id   = col.dataset.lid;
        _startDrag(e, col);
    }
}

function _startDrag(e, el) {
    const rect = el.getBoundingClientRect();
    drag.offX = e.clientX - rect.left;
    drag.offY = e.clientY - rect.top;
    drag.startX = e.clientX;
    drag.startY = e.clientY;

    /* ghost visuel */
    const ghost = el.cloneNode(true);
    ghost.id = '';
    ghost.style.cssText = `
        position:fixed; z-index:9999; pointer-events:none; opacity:.85;
        width:${rect.width}px; left:${e.clientX - drag.offX}px; top:${e.clientY - drag.offY}px;
        box-shadow:0 16px 48px rgba(0,0,0,.7); transform:rotate(1.5deg) scale(1.03);
        transition:none; border:1px solid rgba(0,229,255,.5);
    `;
    document.body.appendChild(ghost);
    drag.ghost = ghost;
    drag.active = false; /* devient true après 5px de mouvement */

    e.preventDefault();
}

function onDragMove(e) {
    if (!drag.ghost) return;

    const dx = e.clientX - drag.startX;
    const dy = e.clientY - drag.startY;
    if (!drag.active && Math.hypot(dx, dy) < 5) return;
    drag.active = true;

    /* déplacer le ghost */
    drag.ghost.style.left = (e.clientX - drag.offX) + 'px';
    drag.ghost.style.top  = (e.clientY - drag.offY) + 'px';
    drag.el.style.opacity = '0.25';

    if (drag.type === 'card') _moveCard(e);
    else                      _moveCol(e);
}

/* placeholder pour carte */
let _ph = null;
function _ensurePh() {
    if (!_ph) { _ph = document.createElement('div'); _ph.className = 'drop-ph'; }
    return _ph;
}

function _moveCard(e) {
    const ph = _ensurePh();
    /* trouver le cards-wrap sous le curseur */
    drag.ghost.style.display = 'none';
    const under = document.elementFromPoint(e.clientX, e.clientY);
    drag.ghost.style.display = '';
    if (!under) return;

    const cw = under.closest('.cards-wrap');
    if (!cw) return;

    /* retirer la surbrillance des autres wraps */
    document.querySelectorAll('.cards-wrap.dov').forEach(w => { if(w!==cw) w.classList.remove('dov'); });
    cw.classList.add('dov');

    /* insérer le placeholder */
    const cards = [...cw.querySelectorAll('.dcard')].filter(c => c !== drag.el && c !== ph);
    let inserted = false;
    for (const c of cards) {
        const r = c.getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2) { cw.insertBefore(ph, c); inserted = true; break; }
    }
    if (!inserted) cw.appendChild(ph);
}

function _moveCol(e) {
    const board = document.getElementById('boardScroll');
    drag.ghost.style.display = 'none';
    const under = document.elementFromPoint(e.clientX, e.clientY);
    drag.ghost.style.display = '';
    if (!under) return;
    const targetCol = under.closest('.list-col');
    if (!targetCol || targetCol === drag.el) return;
    const r = targetCol.getBoundingClientRect();
    if (e.clientX < r.left + r.width / 2) board.insertBefore(drag.el, targetCol);
    else board.insertBefore(drag.el, targetCol.nextSibling);
}

async function onDragEnd(e) {
    if (!drag.ghost) return;
    if (!drag.active) { /* simple clic, pas de drag */ _cleanDrag(); return; }

    drag.ghost.remove();
    drag.el.style.opacity = '';
    document.querySelectorAll('.cards-wrap.dov').forEach(w => w.classList.remove('dov'));

    if (drag.type === 'card' && _ph && _ph.parentNode) {
        const cw = _ph.parentNode;
        cw.insertBefore(drag.el, _ph);
        _ph.remove(); _ph = null;
        const lid = cw.id.replace('cw-','');
        updateCnt(drag.fromLid);
        updateCnt(lid);
        const ids = [...cw.querySelectorAll('.dcard')].map(c => c.dataset.cardId);
        await api({ action:'reorder_cards', list_id:lid, ids:JSON.stringify(ids) });
    } else if (drag.type === 'col') {
        saveColOrder();
    }

    _cleanDrag();
}

function _cleanDrag() {
    if (drag.ghost) { drag.ghost.remove(); drag.ghost = null; }
    if (drag.el)    drag.el.style.opacity = '';
    if (_ph && _ph.parentNode) { _ph.remove(); }
    _ph = null;
    drag = { active:false, type:null, el:null, ghost:null, id:null, fromLid:null, startX:0, startY:0, offX:0, offY:0 };
}

/* --- supprime les anciens handlers HTML5 des colonnes & cartes créées dynamiquement --- */
function removeHtml5Drag(el) {
    el.removeAttribute('draggable');
    el.removeAttribute('ondragstart');
    el.removeAttribute('ondragend');
    el.removeAttribute('ondragover');
    el.removeAttribute('ondrop');
    el.removeAttribute('ondragleave');
}

function updateCnt(lid) {
    const n = document.querySelectorAll(`#cw-${lid} .dcard`).length;
    const el = document.getElementById('cnt-'+lid);
    if (el) el.textContent = n;
}
async function saveColOrder() {
    const ids = [...document.querySelectorAll('.list-col')].map(c => c.dataset.lid);
    await api({ action:'reorder_lists', ids:JSON.stringify(ids) });
}

/* ─── ADD CARD ─── */
function showAcf(lid){ document.getElementById('acb-'+lid).style.display='none'; const f=document.getElementById('acf-'+lid); f.style.display='block'; f.querySelector('textarea').focus(); }
function cancelAcf(lid){ document.getElementById('acf-'+lid).style.display='none'; document.getElementById('acb-'+lid).style.display='flex'; }
function acfKey(e,lid){ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();doAddCard(lid);} if(e.key==='Escape')cancelAcf(lid); }
async function doAddCard(lid){
    const f=document.getElementById('acf-'+lid); const ta=f.querySelector('textarea');
    const title=ta.value.trim(); if(!title)return;
    const res=await api({action:'add_card',list_id:lid,title});
    if(!res.success){toast('Erreur','e');return;}
    const cw=document.getElementById('cw-'+lid);
    const div=document.createElement('div');
    div.innerHTML=`<div class="dcard" id="card-${res.id}" data-card-id="${res.id}" data-p="" data-title="${title.toLowerCase()}" onclick="openCard(${res.id})"><div class="card-body"><div class="card-title">${esc(title)}</div><div class="card-foot"></div></div></div>`;
    cw.appendChild(div.firstElementChild);
    ta.value=''; cancelAcf(lid); updateCnt(lid); toast('Carte ajoutée !');
}

/* ─── ADD LIST ─── */
function openAddList(){
    colColor = '#00e5ff';
    document.getElementById('almSwatch').style.background = colColor;
    document.getElementById('almColorLabel').textContent = colColor.toUpperCase();
    document.getElementById('newColName').value = '';
    document.getElementById('addListOv').classList.remove('hidden');
    setTimeout(()=>document.getElementById('newColName').focus(), 50);
}
function closeAddList(){ document.getElementById('addListOv').classList.add('hidden'); }
async function doAddList(){
    const name=document.getElementById('newColName').value.trim(); if(!name)return;
    const res=await api({action:'add_list',name,color:colColor}); if(!res.success){toast('Erreur','e');return;}
    const lid=res.id; const board=document.getElementById('boardScroll'); const addBtn=board.querySelector('.add-list-btn');
    const col=document.createElement('div');
    col.className='list-col'; col.id='col-'+lid; col.dataset.lid=lid;
    col.innerHTML=`
        <div class="col-hdr" style="border-color:${colColor}33;">
            <div class="col-dot" style="background:${colColor};box-shadow:0 0 7px ${colColor}66;"></div>
            <input class="col-name" type="text" value="${esc(name)}" onchange="saveListName(${lid},this.value,currentColColors[${lid}]||'${colColor}')" onclick="event.stopPropagation()" onmousedown="event.stopPropagation()">
            <span class="col-cnt" id="cnt-${lid}">0</span>
            <button class="col-more" onclick="openCtx(event,${lid})"><i class="fas fa-ellipsis-h"></i></button>
        </div>
        <div class="cards-wrap" id="cw-${lid}"></div>
        <div id="acf-${lid}" class="add-card-form">
            <textarea placeholder="Titre..." rows="3" onkeydown="acfKey(event,${lid})"></textarea>
            <div class="acf-row">
                <button class="btn btn-p btn-sm" onclick="doAddCard(${lid})"><i class="fas fa-plus"></i> Ajouter</button>
                <button class="btn btn-g btn-sm" onclick="cancelAcf(${lid})"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <button class="add-card-row" id="acb-${lid}" onclick="showAcf(${lid})"><i class="fas fa-plus"></i> Ajouter une carte</button>
    `;
    currentColColors[lid] = colColor;
    board.insertBefore(col, addBtn);
    closeAddList(); toast('Colonne créée !');
}
async function saveListName(lid,name,color){ await api({action:'update_list',id:lid,name,color:color||'#00e5ff'}); }

/* ─── CTX MENU ─── */
function openCtx(e,lid){ e.stopPropagation(); curListId=lid; const m=document.getElementById('ctxMenu'); m.classList.remove('hidden'); m.style.top=Math.min(e.clientY,window.innerHeight-120)+'px'; m.style.left=Math.min(e.clientX,window.innerWidth-160)+'px'; }
function closeCtxMenu(){ document.getElementById('ctxMenu').classList.add('hidden'); }
function ctxClear(){ const lid=curListId; closeCtxMenu(); LSPD.confirm({title:'Vider la colonne',msg:'Toutes les cartes de cette colonne seront supprimées (archivées).',okLabel:'Vider',okClass:'btn-danger',callback:async()=>{ const cards=document.querySelectorAll(`#cw-${lid} .dcard`); for(const c of cards){await api({action:'delete_card',id:c.dataset.cardId});c.remove();} updateCnt(lid); toast('Colonne vidée'); }}); }
function ctxDel(){ const lid=curListId; closeCtxMenu(); LSPD.confirm({title:'Supprimer la colonne',msg:'Cette colonne et tout son contenu seront supprimés.',okLabel:'Supprimer',okClass:'btn-danger',callback:async()=>{ await api({action:'delete_list',id:lid}); document.getElementById('col-'+lid)?.remove(); toast('Colonne supprimée'); }}); }
function ctxColor(){ closeCtxMenu(); const cur=currentColColors[curListId]||'#00e5ff'; openColorPicker('colonne_ctx', cur); }

/* ─── FULL COLOR PICKER ENGINE ─── */
const CPK_PRESETS = [
    '#00e5ff','#0066ff','#8b5cf6','#ec4899','#ef4444',
    '#f59e0b','#22c55e','#f97316','#14b8a6','#64748b',
    '#a78bfa','#fbbf24','#34d399','#f87171','#60a5fa',
    '#ffffff','#94a3b8','#1e293b',
];
let cpkH=180, cpkS=100, cpkV=100; // HSV
let cpkMode='colonne'; // 'colonne' | 'colonne_ctx' | 'label'

function openColorPicker(mode, initHex){
    cpkMode = mode;
    const hex = initHex || (mode==='label' ? lblColor : colColor);
    const safeHex = (hex&&hex.length===7) ? hex : '#00e5ff';
    const {h,s,v} = hexToHsv(safeHex);
    cpkH=h; cpkS=s; cpkV=v;
    cpkDraw();
    cpkUpdateUI();
    document.getElementById('cpkOv').classList.remove('hidden');
    buildCpkPresets();
}
function closeCpk(){ document.getElementById('cpkOv').classList.add('hidden'); }
function cpkConfirm(){
    const hex = hsvToHex(cpkH, cpkS, cpkV);
    if(cpkMode==='colonne'){
        colColor = hex;
        document.getElementById('almSwatch').style.background = hex;
        document.getElementById('almColorLabel').textContent = hex.toUpperCase();
    } else if(cpkMode==='colonne_ctx'){
        const col = document.getElementById('col-'+curListId);
        if(col){
            col.querySelector('.col-dot').style.cssText=`background:${hex};box-shadow:0 0 7px ${hex}66;`;
            col.querySelector('.col-hdr').style.borderColor=hex+'33';
            const nm = col.querySelector('.col-name').value;
            currentColColors[curListId] = hex;
            api({action:'update_list', id:curListId, name:nm, color:hex});
        }
        toast('Couleur modifiée !');
    } else if(cpkMode==='label'){
        lblColor = hex;
        const sw=document.getElementById('lblSwatch');
        if(sw){ sw.style.background=hex; document.getElementById('lblColorLabel').textContent=hex.toUpperCase(); }
    } else if(cpkMode==='card'){
        setCardColor(hex);
        // Ajouter un swatch custom dynamiquement si pas déjà présent
        let existing = document.querySelector(`#cardColorRow .cc-swatch[data-color="${hex}"]`);
        if(!existing){
            const sw=document.createElement('div'); sw.className='cc-swatch on';
            sw.dataset.color=hex; sw.style.background=`${hex}44`; sw.style.borderColor=`${hex}88`;
            sw.title='Couleur perso'; sw.onclick=()=>setCardColor(hex);
            document.getElementById('cardColorRow').insertBefore(sw, document.querySelector('#cardColorRow .cc-custom-btn'));
        }
    }
    closeCpk();
}

/* Canvas draw */
function cpkDraw(){
    const canvas = document.getElementById('cpkSV');
    const wrap = document.getElementById('cpkSVWrap');
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    // Base hue
    ctx.fillStyle = `hsl(${cpkH},100%,50%)`; ctx.fillRect(0,0,W,H);
    // White gradient L→R
    const wg = ctx.createLinearGradient(0,0,W,0);
    wg.addColorStop(0,'rgba(255,255,255,1)'); wg.addColorStop(1,'rgba(255,255,255,0)');
    ctx.fillStyle=wg; ctx.fillRect(0,0,W,H);
    // Black gradient T→B
    const bg = ctx.createLinearGradient(0,0,0,H);
    bg.addColorStop(0,'rgba(0,0,0,0)'); bg.addColorStop(1,'rgba(0,0,0,1)');
    ctx.fillStyle=bg; ctx.fillRect(0,0,W,H);
    // Cursor - use actual displayed dimensions
    const dispW = wrap.clientWidth || W;
    const dispH = wrap.clientHeight || H;
    const cx = (cpkS/100)*dispW, cy = (1-cpkV/100)*dispH;
    document.getElementById('cpkSVCur').style.left=cx+'px';
    document.getElementById('cpkSVCur').style.top=cy+'px';
}

function cpkUpdateUI(){
    const hex = hsvToHex(cpkH,cpkS,cpkV);
    document.getElementById('cpkPreview').style.background = hex;
    document.getElementById('cpkHex').value = hex.toUpperCase();
    // Hue thumb
    const ht = document.getElementById('cpkHueThumb');
    ht.style.left = (cpkH/360*100)+'%';
    ht.style.background = `hsl(${cpkH},100%,50%)`;
    // Presets highlight
    document.querySelectorAll('.cpk-pre').forEach(p=>{
        p.classList.toggle('on', p.dataset.c.toUpperCase()===hex.toUpperCase());
    });
}

function buildCpkPresets(){
    const c=document.getElementById('cpkPresets'); c.innerHTML='';
    CPK_PRESETS.forEach(col=>{
        const sw=document.createElement('div'); sw.className='cpk-pre'; sw.style.background=col; sw.dataset.c=col;
        sw.onclick=()=>{ const {h,s,v}=hexToHsv(col); cpkH=h;cpkS=s;cpkV=v; cpkDraw(); cpkUpdateUI(); };
        c.appendChild(sw);
    });
}

function cpkHexInput(val){
    if(val.length===7 && /^#[0-9a-fA-F]{6}$/.test(val)){
        const {h,s,v}=hexToHsv(val); cpkH=h;cpkS=s;cpkV=v; cpkDraw(); cpkUpdateUI();
    }
}

/* SV canvas mouse/touch */
function cpkSVEvent(e){
    const wrap = document.getElementById('cpkSVWrap');
    const r = wrap.getBoundingClientRect();
    const px = (e.touches?e.touches[0].clientX:e.clientX)-r.left;
    const py = (e.touches?e.touches[0].clientY:e.clientY)-r.top;
    cpkS = Math.max(0,Math.min(100, px/r.width*100));
    cpkV = Math.max(0,Math.min(100, (1-py/r.height)*100));
    cpkDraw(); cpkUpdateUI();
}
(function(){
    let svDown=false, hueDown=false;
    document.addEventListener('DOMContentLoaded',()=>{
        const sv = document.getElementById('cpkSVWrap');
        sv.addEventListener('mousedown', e=>{ svDown=true; cpkSVEvent(e); });
        sv.addEventListener('touchstart', e=>{ svDown=true; cpkSVEvent(e); e.preventDefault(); },{passive:false});
        const hueW = document.getElementById('cpkHueWrap');
        hueW.addEventListener('mousedown', e=>{ hueDown=true; cpkHueEvent(e); });
        hueW.addEventListener('touchstart', e=>{ hueDown=true; cpkHueEvent(e); e.preventDefault(); },{passive:false});

        /* ── init drag & drop souris (FiveM compatible) ── */
        /* Nettoyer tous les attributs HTML5 drag hérités */
        document.querySelectorAll('[draggable]').forEach(el => {
            el.removeAttribute('draggable');
            ['ondragstart','ondragend','ondragover','ondrop','ondragleave'].forEach(a => el.removeAttribute(a));
        });
        initDrag();
    });
    document.addEventListener('mousemove', e=>{ if(svDown)cpkSVEvent(e); if(hueDown)cpkHueEvent(e); });
    document.addEventListener('touchmove', e=>{ if(svDown)cpkSVEvent(e); if(hueDown)cpkHueEvent(e); },{passive:false});
    document.addEventListener('mouseup', ()=>{ svDown=false; hueDown=false; });
    document.addEventListener('touchend', ()=>{ svDown=false; hueDown=false; });
})();

function cpkHueEvent(e){
    const r = document.getElementById('cpkHueWrap').getBoundingClientRect();
    const px = (e.touches?e.touches[0].clientX:e.clientX)-r.left;
    cpkH = Math.max(0,Math.min(360, px/r.width*360));
    cpkDraw(); cpkUpdateUI();
}

/* Color conversion */
function hsvToHex(h,s,v){
    s/=100; v/=100;
    const c=v*s, x=c*(1-Math.abs((h/60)%2-1)), m=v-c;
    let r,g,b;
    if(h<60){r=c;g=x;b=0;}else if(h<120){r=x;g=c;b=0;}else if(h<180){r=0;g=c;b=x;}
    else if(h<240){r=0;g=x;b=c;}else if(h<300){r=x;g=0;b=c;}else{r=c;g=0;b=x;}
    const toH=n=>Math.round((n+m)*255).toString(16).padStart(2,'0');
    return '#'+toH(r)+toH(g)+toH(b);
}
function hexToHsv(hex){
    let r=parseInt(hex.slice(1,3),16)/255, g=parseInt(hex.slice(3,5),16)/255, b=parseInt(hex.slice(5,7),16)/255;
    const max=Math.max(r,g,b), min=Math.min(r,g,b), d=max-min;
    let h=0,s=max?d/max:0,v=max;
    if(d){
        if(max===r)h=((g-b)/d+6)%6;
        else if(max===g)h=(b-r)/d+2;
        else h=(r-g)/d+4;
        h*=60;
    }
    return {h:Math.round(h),s:Math.round(s*100),v:Math.round(v*100)};
}

/* ─── CARD MODAL ─── */
async function openCard(id){
    curCard=id;
    document.getElementById('cardModal').classList.remove('hidden');
    document.getElementById('cardModal').scrollTop=0;
    document.getElementById('myAv').textContent=ME.slice(0,2).toUpperCase();
    const res=await apiGet({action:'get_card',id});
    if(!res.success)return;
    const card=res.card;

    // List name
    const ln=document.querySelector(`.list-col[data-lid="${card.list_id}"] .col-name`);
    document.getElementById('mListName').textContent=ln?ln.value:'';

    // Title
    const ta=document.getElementById('mTitle'); ta.value=card.title; autoH(ta);

    // Fields
    document.getElementById('mDesc').value=card.description||'';
    document.getElementById('mAssigned').value=card.assigned_to||'';
    document.getElementById('mLocation').value=card.location||'';
    document.getElementById('mDue').value=card.due_date?card.due_date.replace(' ','T').slice(0,16):'';

    // Priority
    setPrioBtn(null, card.priority||'');

    // Card color
    cardColor = card.card_color||'';
    setCardColor(cardColor, false); // false = ne pas animer

    // Cover
    if(card.cover_url){
        document.getElementById('coverPreviewImg').src=card.cover_url;
        document.getElementById('coverPreviewWrap').style.display='block';
        document.getElementById('coverDrop').style.display='none';
    } else {
        document.getElementById('coverPreviewWrap').style.display='none';
        document.getElementById('coverDrop').style.display='block';
    }

    // Officiers assignés (structuré) + spécialités auto
    selectedAgents = Array.isArray(card.assigned_agents)?card.assigned_agents:[];
    renderAgentChips();

    // Vehicle + Plate
    document.getElementById('mVehicle').value=card.patrol_vehicle||'';
    document.getElementById('mPlate').value=card.vehicle_plate||'';
    renderPlateBlock(card.available_plates||[], card.vehicle_plate||'', !!card.patrol_vehicle);

    // Code Ten
    setTagChecks('tenOpts', card.code_ten_tags||[]);
    updateTagSel('ten');

    // Other tags
    setTagChecks('otherOpts', card.other_tags||[]);
    updateTagSel('other');

    // Labels
    renderModalLabels(card.labels, card.all_labels);

    // Global checklists state
    _useGlobal     = !!card.use_global_checklists;
    _globalChecks  = (card.global_checks||[]).map(x=>parseInt(x));
    document.getElementById('clGlobalTog').classList.toggle('active', _useGlobal);

    // Checklists (perso + globales si activées)
    renderChecklists(card.checklists||[]);

    // Comments
    renderComments(card.comments||[]);

    // Historique
    loadCardHistory();
}
function closeCard(){ document.getElementById('cardModal').classList.add('hidden'); curCard=null; }

function _gatherCardPayload(){
    const tens=JSON.stringify([...document.querySelectorAll('#tenOpts input:checked')].map(i=>i.value));
    const others=JSON.stringify([...document.querySelectorAll('#otherOpts input:checked')].map(i=>i.value));
    return {
        action:'update_card', id:curCard,
        title:document.getElementById('mTitle').value,
        description:document.getElementById('mDesc').value,
        priority:document.getElementById('mPrio').value,
        due_date:document.getElementById('mDue').value,
        assigned_to:document.getElementById('mAssigned').value,
        assigned_agents:JSON.stringify(selectedAgents||[]),
        location:document.getElementById('mLocation').value,
        patrol_vehicle:document.getElementById('mVehicle').value,
        vehicle_plate:document.getElementById('mPlate').value,
        code_ten_tags:tens,
        other_tags:others,
        card_color:cardColor
    };
}
async function saveCard(){
    if(!curCard)return;
    const res=await api(_gatherCardPayload());
    if(res.success){ updateCardDOM(); toast('Carte enregistrée !'); }
    else toast(res.error||'Erreur','e');
}

async function saveAndClose(){
    if(!curCard){ closeCard(); return; }
    const res=await api(_gatherCardPayload());
    if(res.success){ updateCardDOM(); closeCard(); }
    else { toast(res.error||'Erreur','e'); /* ne pas fermer si erreur (ex: plaque déjà prise) */ }
}

function updateCardDOM(){
    const el = document.getElementById('card-'+curCard); if(!el) return;
    const prio    = document.getElementById('mPrio').value;
    const title   = document.getElementById('mTitle').value;
    const vehicle = document.getElementById('mVehicle').value;
    const dueDT   = document.getElementById('mDue').value;
    const assigned= (selectedAgents&&selectedAgents.length)?selectedAgents.map(a=>a.name+(a.matricule?' #'+a.matricule:'')).join(', '):document.getElementById('mAssigned').value.trim();
    const loc     = document.getElementById('mLocation').value.trim();
    const descRaw = document.getElementById('mDesc').value.trim();

    const gradeTags  = [...new Set((selectedAgents||[]).flatMap(a=>String(a.divisions||'').split(',').map(s=>s.trim()).filter(Boolean)))];
    const tenTags    = [...document.querySelectorAll('#tenOpts input:checked')].map(i=>i.value);
    const otherTags  = [...document.querySelectorAll('#otherOpts input:checked')].map(i=>i.value);
    const activeLbls = [...document.querySelectorAll('#mLabels .lbl-tog.on')];

    // ── Card color ──
    el.dataset.p = prio;
    el.dataset.title = title.toLowerCase();
    if(cardColor){
        const r=parseInt(cardColor.slice(1,3),16),g=parseInt(cardColor.slice(3,5),16),b=parseInt(cardColor.slice(5,7),16);
        el.style.background=`rgba(${r},${g},${b},0.18)`;
        el.style.borderColor=`rgba(${r},${g},${b},0.35)`;
        el.dataset.bg=cardColor;
    } else {
        el.style.background=''; el.style.borderColor=''; el.removeAttribute('data-bg');
    }

    // ── Cover image sur carte ──
    const coverUrl = document.getElementById('coverPreviewImg').src;
    const hasCover = document.getElementById('coverPreviewWrap').style.display !== 'none' && coverUrl && !coverUrl.endsWith('/dispatch.php');
    let coverHtml = '';
    if(hasCover){
        coverHtml = `<div class="card-cover-wrap">
            <img class="card-cover" src="${coverUrl}" alt="" style="cursor:zoom-in;" onclick="event.stopPropagation();openLbx('${coverUrl}')">
            <div class="card-cover-ttl">${esc(title)}</div>
            <span class="card-zoom-btn" onclick="event.stopPropagation();openLbx('${coverUrl}')"><i class="fas fa-expand-alt"></i></span>
        </div>`;
    }

    // ── Tags HTML ──
    const plate = (document.getElementById('mPlate').value||'').trim();
    let tagsHtml = '';
    gradeTags.forEach(g=>{ tagsHtml += `<span class="ctag ct-grade"><i class="fas fa-shield-alt" style="font-size:.5rem"></i> ${esc(g)}</span>`; });
    if(vehicle){
        const plateLbl = plate ? ` · ${esc(plate)}` : '';
        tagsHtml += `<span class="ctag ct-veh"><i class="fas fa-car" style="font-size:.5rem"></i> ${esc(vehicle)}${plateLbl}</span>`;
    }
    tenTags.forEach(t=>{ const col=CODE_TEN_COLORS[t]||'gray'; tagsHtml += `<span class="ctag ct-${col}">📡 ${esc(t)}</span>`; });
    otherTags.forEach(t=>{ const col=OTHER_TAG_COLORS[t]||'gray'; tagsHtml += `<span class="ctag ct-${col}">${esc(t)}</span>`; });

    // ── Labels HTML ──
    let lblsHtml = '';
    if(activeLbls.length){
        lblsHtml='<div class="card-lbls">';
        activeLbls.forEach(btn=>{ lblsHtml+=`<span class="card-lbl" style="background:${btn.style.background}">${esc(btn.dataset.lname||btn.textContent.trim())}</span>`; });
        lblsHtml+='</div>';
    }

    // ── Due badge ──
    let dueBadge='';
    if(dueDT){
        const due=new Date(dueDT).getTime()/1000,now=Date.now()/1000,diff=due-now;
        if(diff<0)         dueBadge=`<span class="cfoot-badge ov"><i class="fas fa-clock"></i> Retard</span>`;
        else if(diff<7200) dueBadge=`<span class="cfoot-badge ds"><i class="fas fa-clock"></i> ${new Date(due*1000).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'})}</span>`;
        else               dueBadge=`<span class="cfoot-badge"><i class="fas fa-clock"></i> ${new Date(due*1000).toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit'})} ${new Date(due*1000).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'})}</span>`;
    }

    // ── Description preview ──
    let descHtml='';
    if(descRaw){
        const short = descRaw.length>120 ? descRaw.slice(0,120)+'…' : descRaw;
        descHtml=`<div class="card-desc-prev">${esc(short)}</div>`;
    }

    // ── Checklist mini (keep) ──
    const clMini = el.querySelector('.cl-mini');
    const clHtml = clMini ? clMini.outerHTML : '';

    // ── Rebuild ──
    let body = coverHtml; // image en fond si présente
    body += '<div class="card-body">';
    if(lblsHtml) body += lblsHtml;
    if(!hasCover) body += `<div class="card-title">${esc(title)}</div>`;
    if(descHtml) body += descHtml;
    if(tagsHtml) body += `<div class="card-tags">${tagsHtml}</div>`;
    body += `<div class="card-foot">`;
    if(prio) body += `<span class="prio-lbl ${prio}">${prio.toUpperCase()}</span>`;
    body += dueBadge;
    if(selectedAgents&&selectedAgents.length){
        const av=selectedAgents.slice(0,5).map(a=>a.avatar?`<img src="${esc(a.avatar)}" style="width:22px;height:22px;border-radius:50%;object-fit:cover;border:2px solid var(--s1);margin-left:-6px">`:`<span style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:inline-grid;place-items:center;color:#fff;font-size:.6rem;font-weight:700;border:2px solid var(--s1);margin-left:-6px">${esc((a.name||'?').slice(0,1).toUpperCase())}</span>`).join('');
        const more=selectedAgents.length>5?' +'+(selectedAgents.length-5):'';
        body+=`<span class="cfoot-badge" style="padding-left:6px"><span style="display:inline-flex;align-items:center;padding-left:6px">${av}</span>${more}</span>`;
    } else if(assigned) body+=`<span class="cfoot-badge"><i class="fas fa-user"></i> ${esc(assigned)}</span>`;
    if(loc)      body+=`<span class="cfoot-badge"><i class="fas fa-map-marker-alt"></i> ${esc(loc)}</span>`;
    body += '</div>';
    if(clHtml) body += clHtml;
    body += '</div>';
    el.innerHTML = body;
}

function deleteCard(){
    if(!curCard)return;
    document.getElementById('delCardOv').classList.remove('hidden');
}
function closeDelModal(){
    const el=document.getElementById('delCardOv');
    if(el) el.classList.add('hidden');
}
async function confirmDeleteCard(){
    closeDelModal();
    if(!curCard)return;
    const res=await api({action:'delete_card',id:curCard});
    if(res.success){
        const el=document.getElementById('card-'+curCard);
        if(el){ const cw=el.closest('.cards-wrap'); el.remove(); if(cw){const lid=cw.id.replace('cw-','');updateCnt(lid);} }
        closeCard(); toast('Carte supprimée');
    } else toast('Erreur lors de la suppression','e');
}

/* ─── COVER ─── */
async function uploadCover(input){
    if(!input.files[0]||!curCard)return;
    const fd=new FormData(); fd.append('image',input.files[0]); fd.append('card_id',curCard);
    const r=await fetch(location.href+'?ajax=1&action=upload_cover',{method:'POST',body:fd});
    const res=await r.json();
    if(res.success){
        document.getElementById('coverPreviewImg').src=res.url;
        document.getElementById('coverPreviewWrap').style.display='block';
        document.getElementById('coverDrop').style.display='none';
        // Update card DOM cover
        const el=document.getElementById('card-'+curCard);
        if(el){
            let cw=el.querySelector('.card-cover-wrap');
            if(!cw){cw=document.createElement('div');cw.className='card-cover-wrap';el.insertBefore(cw,el.firstChild);}
            cw.innerHTML=`<img class="card-cover" src="${res.url}" alt=""><div class="card-cover-ttl">${esc(document.getElementById('mTitle').value)}</div>`;
        }
        toast('Image ajoutée !');
    } else toast(res.error||'Erreur','e');
}
function handleCoverDrop(e){ e.preventDefault(); const f=e.dataTransfer.files[0]; if(!f)return; const inp=document.getElementById('coverInput'); const dt=new DataTransfer(); dt.items.add(f); inp.files=dt.files; uploadCover(inp); }
async function removeCover(){
    if(!curCard)return;
    await api({action:'remove_cover',card_id:curCard});
    document.getElementById('coverPreviewWrap').style.display='none';
    document.getElementById('coverDrop').style.display='block';
    const el=document.getElementById('card-'+curCard);
    if(el){const cw=el.querySelector('.card-cover-wrap');if(cw)cw.remove();}
    toast('Image supprimée');
}

/* ─── PRIORITY BUTTONS ─── */
function setPrioBtn(btn, forceVal){
    const val = btn ? btn.dataset.p : (forceVal !== undefined ? forceVal : '');
    document.getElementById('mPrio').value = val;
    document.querySelectorAll('.prio-btn').forEach(b => b.classList.toggle('on', b.dataset.p === val));
}

/* ─── GRADES ─── */
function renderGrades(activeTags){
    const c = document.getElementById('mGrades');
    c.innerHTML = '';
    gradesData.forEach(g => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.dataset.g = g.name;
        const isOn = activeTags.includes(g.name);
        btn.className = 'grade-btn' + (isOn ? ' on' : '');

        // Label texte
        const lbl = document.createElement('span');
        lbl.className = 'g-lbl';
        lbl.textContent = g.name;
        btn.appendChild(lbl);

        // Bouton X (toujours présent, caché si pas .on via CSS)
        const rm = document.createElement('span');
        rm.className = 'g-rm';
        rm.title = 'Retirer';
        rm.innerHTML = '<i class="fas fa-times"></i>';
        btn.appendChild(rm);

        // Clic sur le X → retirer
        rm.addEventListener('click', e => {
            e.stopPropagation();
            btn.classList.remove('on');
            // pas besoin de re-render, juste retirer la classe suffit
        });

        // Clic sur le bouton → toggle
        btn.addEventListener('click', e => {
            if(e.target === rm || rm.contains(e.target)) return;
            btn.classList.toggle('on');
        });

        c.appendChild(btn);
    });
}

/* ─── CARD COLOR ─── */
function setCardColor(color, updatePreview=true){
    cardColor = color;
    document.querySelectorAll('#cardColorRow .cc-swatch').forEach(sw=>{
        sw.classList.toggle('on', sw.dataset.color===color);
    });
    // Preview live sur la carte dans le board si ouverte
    if(updatePreview && curCard){
        const el=document.getElementById('card-'+curCard);
        if(el){
            if(color){
                const r=parseInt(color.slice(1,3),16),g=parseInt(color.slice(3,5),16),b=parseInt(color.slice(5,7),16);
                el.style.background=`rgba(${r},${g},${b},0.18)`;
                el.style.borderColor=`rgba(${r},${g},${b},0.35)`;
                el.dataset.bg=color;
            } else {
                el.style.background=''; el.style.borderColor=''; el.removeAttribute('data-bg');
            }
        }
    }
}

/* ─── PLAQUES ─── */
async function onVehicleChange(){
    const sel = document.getElementById('mVehicle');
    const opt = sel.options[sel.selectedIndex];
    const vid = opt ? parseInt(opt.dataset.vid||'0') : 0;
    /* On reset la plaque si on change de véhicule */
    document.getElementById('mPlate').value = '';
    if (!sel.value) {
        document.getElementById('plateBlock').classList.add('hidden');
        return;
    }
    const block = document.getElementById('plateBlock');
    block.classList.remove('hidden');
    document.getElementById('plateGrid').innerHTML = '<div class="plate-empty">Chargement…</div>';
    if (vid <= 0) {
        document.getElementById('plateGrid').innerHTML = '<div class="plate-empty">Véhicule introuvable.</div>';
        return;
    }
    const r = await api({action:'get_plates', vehicle_id:vid, card_id:curCard||0});
    if (!r.success) { document.getElementById('plateGrid').innerHTML = '<div class="plate-empty">Erreur de chargement</div>'; return; }
    renderPlateBlock(r.plates||[], '', true);
}
function renderPlateBlock(plates, currentPlate, hasVehicle){
    const block = document.getElementById('plateBlock');
    if (!hasVehicle) { block.classList.add('hidden'); return; }
    block.classList.remove('hidden');
    const grid = document.getElementById('plateGrid');
    const cur  = document.getElementById('plateCurrent');
    const clr  = document.getElementById('plateClearBtn');
    if (currentPlate) { cur.textContent = currentPlate; cur.classList.remove('empty'); clr.classList.remove('hidden'); }
    else              { cur.textContent = '— Aucune —';   cur.classList.add('empty');    clr.classList.add('hidden'); }
    grid.innerHTML = '';
    if (!plates.length) {
        grid.innerHTML = '<div class="plate-empty">Aucune plaque configurée pour ce véhicule.<br><span style="font-size:.7rem;opacity:.7;">Ajoutez-en via le panel Staff &rarr; Custom Dispatch.</span></div>';
        return;
    }
    plates.forEach(p=>{
        const chip = document.createElement('div');
        const isSelected = (p.plate === currentPlate);
        const isFree = !p.used || isSelected;
        chip.className = 'plate-chip ' + (isFree ? 'free' : 'busy') + (isSelected ? ' selected' : '');
        chip.textContent = p.plate;
        if (isFree) chip.onclick = ()=>selectPlate(p.plate);
        else chip.title = 'Plaque déjà utilisée par une autre carte';
        grid.appendChild(chip);
    });
}
function selectPlate(plate){
    document.getElementById('mPlate').value = plate;
    document.querySelectorAll('#plateGrid .plate-chip').forEach(c=>{
        const isSel = c.textContent === plate;
        c.classList.toggle('selected', isSel && c.classList.contains('free'));
    });
    const cur = document.getElementById('plateCurrent');
    cur.textContent = plate; cur.classList.remove('empty');
    document.getElementById('plateClearBtn').classList.remove('hidden');
}
function clearPlate(){
    document.getElementById('mPlate').value = '';
    document.querySelectorAll('#plateGrid .plate-chip.selected').forEach(c=>c.classList.remove('selected'));
    const cur = document.getElementById('plateCurrent');
    cur.textContent = '— Aucune —'; cur.classList.add('empty');
    document.getElementById('plateClearBtn').classList.add('hidden');
}

/* ─── LIGHTBOX ─── */
function openLbx(url){
    document.getElementById('lbxImg').src=url;
    document.getElementById('lbxOv').classList.remove('hidden');
    document.body.style.overflow='hidden';
}
function closeLbx(){
    document.getElementById('lbxOv').classList.add('hidden');
    document.getElementById('lbxImg').src='';
    document.body.style.overflow='';
}
// Fermer lightbox avec Escape (géré dans le listener global)

/* ─── TAG DROPDOWN ─── */
function toggleDD(ddId){
    const dd=document.getElementById(ddId);
    const panel=dd.querySelector('.tag-panel');
    const trig=dd.querySelector('.tag-trigger');
    const isOpen=panel.classList.contains('open');
    // close all
    document.querySelectorAll('.tag-panel').forEach(p=>p.classList.remove('open'));
    document.querySelectorAll('.tag-trigger').forEach(t=>t.classList.remove('open'));
    if(!isOpen){panel.classList.add('open');trig.classList.add('open');dd.querySelector('.tag-srch').focus();}
}
function filterTagOpts(panelId, q){
    const panel=document.getElementById(panelId);
    panel.querySelectorAll('.tag-opt').forEach(opt=>{
        const txt=opt.querySelector('.tag-opt-lbl').textContent.toLowerCase();
        opt.style.display=txt.includes(q.toLowerCase())?'':'none';
    });
}
function setTagChecks(optsId, values){
    document.querySelectorAll(`#${optsId} input[type="checkbox"]`).forEach(cb=>{
        cb.checked=values.includes(cb.value);
    });
}
function updateTagSel(type){
    const optsId=type==='ten'?'tenOpts':'otherOpts';
    const selId=type==='ten'?'tenSelTags':'otherSelTags';
    const colors=type==='ten'?CODE_TEN_COLORS:OTHER_TAG_COLORS;
    const sel=document.getElementById(selId); sel.innerHTML='';
    document.querySelectorAll(`#${optsId} input:checked`).forEach(cb=>{
        const col=colors[cb.value]||'gray';
        const cls=TAG_CSS[col]||'ct-gray';
        const span=document.createElement('span');
        span.className='ctag '+cls;
        span.title='Cliquez pour retirer';
        span.innerHTML=(type==='ten'?'📡 ':'')+esc(cb.value);
        span.onclick=()=>removeSelectedTag(type, cb.value);
        sel.appendChild(span);
    });
}
function removeSelectedTag(type, value){
    const optsId = type==='ten' ? 'tenOpts' : 'otherOpts';
    const cb = [...document.querySelectorAll(`#${optsId} input[type="checkbox"]`)].find(c=>c.value===value);
    if (cb) { cb.checked = false; updateTagSel(type); }
}

/* ─── LABELS ─── */
function renderModalLabels(active, all){
    const activeIds=active.map(l=>String(l.id));
    const c=document.getElementById('mLabels'); c.innerHTML='';
    all.forEach(l=>{
        const isOn=activeIds.includes(String(l.id));
        const btn=document.createElement('div');
        btn.className='lbl-tog'+(isOn?' on':'');
        btn.style.background=l.color; btn.dataset.lid=l.id; btn.dataset.lname=l.name;
        btn.innerHTML=(isOn?'<i class="fas fa-check"></i> ':'')+esc(l.name);
        btn.onclick=()=>toggleLabel(l.id,btn,l.color,l.name);
        c.appendChild(btn);
    });
}
async function toggleLabel(lid,btn,color,name){
    const res=await api({action:'toggle_card_label',card_id:curCard,label_id:lid});
    if(!res.success)return;
    const on=res.state==='added';
    btn.classList.toggle('on',on);
    btn.innerHTML=(on?'<i class="fas fa-check"></i> ':'')+esc(name);
}

/* ─── LABEL MANAGER ─── */
function openLabelMgr(){
    lblColor = '#00e5ff';
    document.getElementById('lblSwatch').style.background = lblColor;
    document.getElementById('lblColorLabel').textContent = lblColor.toUpperCase();
    document.getElementById('lblModal').classList.remove('hidden');
    renderLblList();
}
function closeLblMgr(){ document.getElementById('lblModal').classList.add('hidden'); }
function renderLblList(){
    const c=document.getElementById('lblList'); c.innerHTML='';
    lblsData.forEach(l=>{
        const row=document.createElement('div'); row.className='mgr-item';
        row.innerHTML=`<div style="width:24px;height:24px;border-radius:5px;background:${l.color};flex-shrink:0;"></div><span>${esc(l.name)}</span><button class="btn btn-r btn-sm" onclick="delLabel(${l.id},this.closest('.mgr-item'))"><i class="fas fa-trash"></i></button>`;
        c.appendChild(row);
    });
}
async function createLabel(){
    const name=document.getElementById('newLblName').value.trim(); if(!name)return;
    const res=await api({action:'add_label',name,color:lblColor});
    if(res.success){ lblsData.push({id:res.id,name,color:lblColor}); renderLblList(); document.getElementById('newLblName').value=''; toast('Label créé !'); }
}
function delLabel(id,row){
    LSPD.confirm({title:'Supprimer le label',msg:'Ce label sera retiré de toutes les cartes.',okLabel:'Supprimer',okClass:'btn-danger',callback:async()=>{
    await api({action:'delete_label',id}); row.remove(); lblsData=lblsData.filter(l=>l.id!==id); toast('Label supprimé');
    }});
}

/* ─── MANAGE MODAL (vehicle / grade) ─── */
function openMgr(mode){
    mgrMode=mode;
    const isV=mode==='vehicle';
    document.getElementById('mgrTitle').textContent=isV?'VÉHICULES':'GRADES';
    document.getElementById('mgrIco').innerHTML=`<i class="fas fa-${isV?'car':'shield-alt'}"></i>`;
    document.getElementById('mgrModal').classList.remove('hidden');
    renderMgrList();
}
function closeMgr(){ document.getElementById('mgrModal').classList.add('hidden'); }
function renderMgrList(){
    const data=mgrMode==='vehicle'?vehiclesData:gradesData;
    const c=document.getElementById('mgrList'); c.innerHTML='';
    data.forEach(item=>{
        const row=document.createElement('div'); row.className='mgr-item';
        row.innerHTML=`<span>${esc(item.name)}</span><button class="btn btn-r btn-sm" onclick="mgrDel(${item.id},this.closest('.mgr-item'))"><i class="fas fa-trash"></i></button>`;
        c.appendChild(row);
    });
}
async function mgrAdd(){
    const name=document.getElementById('mgrNewName').value.trim(); if(!name)return;
    const action=mgrMode==='vehicle'?'add_vehicle':'add_grade';
    const res=await api({action,name});
    if(res.success){
        const newItem={id:res.id,name};
        if(mgrMode==='vehicle'){ vehiclesData.push(newItem); document.getElementById('mVehicle').innerHTML='<option value="">— Aucun —</option>'+vehiclesData.map(v=>`<option value="${esc(v.name)}">${esc(v.name)}</option>`).join(''); }
        else{ gradesData.push(newItem); }
        renderMgrList(); document.getElementById('mgrNewName').value=''; toast('Ajouté !');
    } else toast(res.error||'Erreur','e');
}
function mgrDel(id, row){
    LSPD.confirm({title:'Confirmer la suppression',msg:'Cet élément sera <strong>définitivement</strong> supprimé.',okLabel:'Supprimer',okClass:'btn-danger',callback:async()=>{
    const action=mgrMode==='vehicle'?'delete_vehicle':'delete_grade';
    const res=await api({action,id});
    if(res.success){
        row.remove();
        if(mgrMode==='vehicle') vehiclesData=vehiclesData.filter(v=>v.id!==id);
        else gradesData=gradesData.filter(g=>g.id!==id);
        toast('Supprimé');
    } else toast(res.error||'Erreur','e');
    }});
}

/* ─── CHECKLIST ─── */
let _useGlobal     = false;
let _globalChecks  = [];   // ids item cochés
let _globalCLCache = null; // cache des checklists globales

function renderChecklists(cls){
    const c=document.getElementById('mChecklists'); c.innerHTML='';
    cls.forEach(cl=>appendCL(cl));
    if (_useGlobal) appendGlobalChecklists();
}

async function appendGlobalChecklists(){
    if (!_globalCLCache) {
        const r = await api({action:'get_global_checklists'});
        if (!r.success) return;
        _globalCLCache = r.checklists || [];
    }
    _globalCLCache.forEach(cl => appendGlobalCLForCard(cl));
}
function appendGlobalCLForCard(cl){
    const items = (cl.items || []).map(it => ({
        ...it,
        is_done: _globalChecks.includes(parseInt(it.id))
    }));
    const total = items.length;
    const done  = items.filter(i=>i.is_done).length;
    const pct   = total ? Math.round(done/total*100) : 0;
    const div   = document.createElement('div');
    div.className = 'cl-block global'; div.id = 'gcl-card-' + cl.id;
    div.innerHTML = `
        <div class="cl-hdr">
            <div class="cl-hdr-t">
                <i class="fas fa-globe" style="font-size:.75rem;"></i>
                ${esc(cl.title)}
                <span class="cl-global-tag"><i class="fas fa-globe" style="font-size:.5rem"></i> Globale</span>
                <span style="font-size:.7rem;color:var(--mu);margin-left:.3rem;">${done}/${total}</span>
            </div>
        </div>
        <div class="cl-prog"><div class="cl-prog-f" id="gclp-${cl.id}" style="width:${pct}%"></div></div>
        <div class="cl-items" id="gcli-${cl.id}">
            ${items.map(i=>`
                <div class="cl-item" id="gclitem-${i.id}">
                    <input type="checkbox" ${i.is_done?'checked':''} onchange="toggleGlobalCheck(${i.id}, ${cl.id})">
                    <span class="cl-it-txt ${i.is_done?'done':''}">${esc(i.text)}</span>
                </div>
            `).join('')}
        </div>
    `;
    document.getElementById('mChecklists').appendChild(div);
}
async function toggleGlobalCheck(itemId, clId){
    if (!curCard) return;
    const r = await api({action:'toggle_card_global_check', card_id:curCard, item_id:itemId});
    if (!r.success) { toast('Erreur', 'e'); return; }
    const wrap = document.getElementById('gclitem-'+itemId);
    if (!wrap) return;
    const cb = wrap.querySelector('input'); const txt = wrap.querySelector('.cl-it-txt');
    txt.classList.toggle('done', cb.checked);
    if (cb.checked) { if (!_globalChecks.includes(itemId)) _globalChecks.push(itemId); }
    else _globalChecks = _globalChecks.filter(x=>x!==itemId);
    /* progress */
    const items = document.querySelectorAll(`#gcli-${clId} .cl-item`);
    const total = items.length, done = [...items].filter(i=>i.querySelector('input').checked).length;
    const pct = total ? Math.round(done/total*100) : 0;
    const bar = document.getElementById('gclp-'+clId); if (bar) bar.style.width = pct+'%';
    const hdr = document.querySelector(`#gcl-card-${clId} .cl-hdr-t span:last-child`); if (hdr) hdr.textContent = `${done}/${total}`;
}
async function toggleCardUseGlobal(){
    if (!curCard) return;
    const next = _useGlobal ? 0 : 1;
    const r = await api({action:'toggle_card_use_global', card_id:curCard, active:next});
    if (!r.success) { toast('Erreur', 'e'); return; }
    _useGlobal = !!r.active;
    document.getElementById('clGlobalTog').classList.toggle('active', _useGlobal);
    /* re-render zone checklists */
    document.querySelectorAll('#mChecklists .cl-block.global').forEach(b=>b.remove());
    if (_useGlobal) {
        _globalCLCache = null;
        await appendGlobalChecklists();
        toast('Checklists globales activées');
    } else {
        toast('Checklists globales désactivées');
    }
}

/* ─── GLOBAL CHECKLISTS MANAGER ─── */
async function openGclMgr(){
    if (!IS_ADMIN) { toast("Réservé aux administrateurs", 'e'); return; }
    document.getElementById('gclModal').classList.remove('hidden');
    await renderGclList();
}
function closeGclMgr(){ document.getElementById('gclModal').classList.add('hidden'); }
async function renderGclList(){
    const c = document.getElementById('gclList');
    c.innerHTML = '<div class="gcl-empty"><i class="fas fa-spinner fa-spin"></i>Chargement…</div>';
    const r = await api({action:'get_global_checklists'});
    if (!r.success) { c.innerHTML = '<div class="gcl-empty"><i class="fas fa-exclamation-triangle"></i>Erreur de chargement</div>'; return; }
    _globalCLCache = r.checklists || [];
    if (!_globalCLCache.length) {
        c.innerHTML = '<div class="gcl-empty"><i class="fas fa-globe"></i>Aucune checklist globale<br><span style="font-size:.72rem;opacity:.7;">Créez-en une ci-dessous.</span></div>';
        return;
    }
    c.innerHTML = '';
    _globalCLCache.forEach(cl => {
        const div = document.createElement('div');
        div.className = 'gcl-block'; div.id = 'gcl-' + cl.id;
        div.innerHTML = `
            <div class="gcl-block-hdr">
                <div class="gcl-block-ttl"><i class="fas fa-globe"></i> ${esc(cl.title)} <span class="gcl-cnt">${(cl.items||[]).length}</span></div>
                <button class="btn btn-r btn-sm" onclick="deleteGcl(${cl.id})" title="Supprimer cette checklist globale (et la retirer de toutes les cartes)"><i class="fas fa-trash"></i></button>
            </div>
            <div class="gcl-items" id="gcl-items-${cl.id}">
                ${(cl.items||[]).map(it=>`
                    <div class="gcl-item" id="gcl-it-${it.id}">
                        <span>${esc(it.text)}</span>
                        <button class="gcl-it-del" onclick="deleteGclItem(${it.id}, ${cl.id})" title="Retirer cet élément"><i class="fas fa-times"></i></button>
                    </div>
                `).join('')}
            </div>
            <div class="gcl-add">
                <input type="text" id="gcl-newit-${cl.id}" placeholder="Ajouter un élément..." onkeydown="if(event.key==='Enter')addGclItem(${cl.id})">
                <button class="btn btn-p btn-sm" onclick="addGclItem(${cl.id})"><i class="fas fa-plus"></i></button>
            </div>
        `;
        c.appendChild(div);
    });
}
async function createGcl(){
    const t = document.getElementById('newGclTitle').value.trim();
    if (!t) { toast('Titre requis', 'e'); return; }
    const r = await api({action:'add_global_checklist', title:t});
    if (!r.success) { toast(r.error||'Erreur', 'e'); return; }
    document.getElementById('newGclTitle').value = '';
    _globalCLCache = null; /* invalide le cache */
    await renderGclList();
    toast('Checklist globale créée');
}
function deleteGcl(id){
    LSPD.confirm({title:'Supprimer la checklist globale',msg:'Elle sera retirée de <strong>TOUTES</strong> les cartes qui l\'utilisent.',okLabel:'Supprimer',okClass:'btn-danger',callback:async()=>{
    const r = await api({action:'delete_global_checklist', id});
    if (!r.success) { toast(r.error||'Erreur', 'e'); return; }
    document.getElementById('gcl-'+id)?.remove();
    _globalCLCache = null;
    /* Si carte courante affiche les globales, on retire le bloc */
    document.getElementById('gcl-card-'+id)?.remove();
    toast('Checklist supprimée partout');
    /* re-render si liste vide */
    if (!document.getElementById('gclList').querySelector('.gcl-block')) renderGclList();
    }});
}
async function addGclItem(clId){
    const inp = document.getElementById('gcl-newit-'+clId);
    const txt = inp.value.trim(); if (!txt) return;
    const r = await api({action:'add_global_item', checklist_id:clId, text:txt});
    if (!r.success) { toast(r.error||'Erreur', 'e'); return; }
    inp.value = '';
    _globalCLCache = null;
    document.getElementById('gcl-items-'+clId).insertAdjacentHTML('beforeend',
        `<div class="gcl-item" id="gcl-it-${r.id}"><span>${esc(r.text)}</span><button class="gcl-it-del" onclick="deleteGclItem(${r.id}, ${clId})"><i class="fas fa-times"></i></button></div>`
    );
    /* refresh count */
    const cnt = document.querySelector(`#gcl-${clId} .gcl-cnt`);
    if (cnt) cnt.textContent = parseInt(cnt.textContent||'0',10)+1;
}
async function deleteGclItem(id, clId){
    const r = await api({action:'delete_global_item', id});
    if (!r.success) { toast(r.error||'Erreur', 'e'); return; }
    document.getElementById('gcl-it-'+id)?.remove();
    _globalCLCache = null;
    const cnt = document.querySelector(`#gcl-${clId} .gcl-cnt`);
    if (cnt) cnt.textContent = Math.max(0, parseInt(cnt.textContent||'0',10)-1);
}
function appendCL(cl){
    const total=cl.items.length, done=cl.items.filter(i=>i.is_done).length, pct=total?Math.round(done/total*100):0;
    const div=document.createElement('div'); div.className='cl-block'; div.id='cl-'+cl.id;
    div.innerHTML=`
        <div class="cl-hdr">
            <div class="cl-hdr-t"><i class="fas fa-tasks" style="color:var(--c);font-size:.75rem;"></i> ${esc(cl.title)} <span style="font-size:.7rem;color:var(--mu);">${done}/${total}</span></div>
            <button class="btn btn-r btn-sm" onclick="delCL(${cl.id})"><i class="fas fa-trash"></i></button>
        </div>
        <div class="cl-prog"><div class="cl-prog-f" id="clp-${cl.id}" style="width:${pct}%"></div></div>
        <div class="cl-items" id="cli-${cl.id}">${cl.items.map(i=>clItemHTML(i,cl.id)).join('')}</div>
        <div class="cl-add">
            <input class="cl-add-i" id="clai-${cl.id}" placeholder="Nouvel élément..." onkeydown="if(event.key==='Enter')addCLItem(${cl.id})">
            <button class="btn btn-p btn-sm" onclick="addCLItem(${cl.id})"><i class="fas fa-plus"></i></button>
        </div>
    `;
    document.getElementById('mChecklists').appendChild(div);
}
function clItemHTML(item,clId){
    return `<div class="cl-item" id="clitem-${item.id}">
        <input type="checkbox" ${item.is_done?'checked':''} onchange="toggleCLItem(${item.id},${clId})">
        <span class="cl-it-txt ${item.is_done?'done':''}">${esc(item.text)}</span>
        <button class="cl-it-del" onclick="delCLItem(${item.id},${clId})"><i class="fas fa-times"></i></button>
    </div>`;
}
function addCL(){
    if(!curCard)return;
    const inp=document.getElementById('clTitleInput'); inp.value='Checklist';
    document.getElementById('clTitleModal').classList.remove('hidden');
    setTimeout(()=>{ inp.focus(); inp.select(); },50);
}
function delCL(id){ LSPD.confirm({title:'Supprimer la checklist',msg:'La checklist et toutes ses tâches seront supprimées.',okLabel:'Supprimer',okClass:'btn-danger',callback:async()=>{ await api({action:'delete_checklist',id}); document.getElementById('cl-'+id)?.remove(); }}); }
async function addCLItem(clId){
    const inp=document.getElementById('clai-'+clId); const text=inp.value.trim(); if(!text)return;
    const res=await api({action:'add_checklist_item',checklist_id:clId,text});
    if(res.success){ document.getElementById('cli-'+clId).insertAdjacentHTML('beforeend',clItemHTML({id:res.id,text,is_done:0},clId)); inp.value=''; updateCLProg(clId); }
}
async function toggleCLItem(id,clId){
    await api({action:'toggle_checklist_item',id});
    const item=document.getElementById('clitem-'+id); if(!item)return;
    const cb=item.querySelector('input'); const txt=item.querySelector('.cl-it-txt');
    txt.classList.toggle('done',cb.checked); updateCLProg(clId);
}
function delCLItem(id,clId){
    LSPD.confirm({title:'Supprimer la tâche',msg:'Cette tâche sera <strong>définitivement</strong> supprimée de la checklist.',okLabel:'Supprimer',okClass:'btn-danger',callback:async()=>{
    await api({action:'delete_checklist_item',id}); document.getElementById('clitem-'+id)?.remove(); updateCLProg(clId);
    }});
}
function updateCLProg(clId){
    const items=document.querySelectorAll(`#cli-${clId} .cl-item`);
    const total=items.length, done=[...items].filter(i=>i.querySelector('input').checked).length;
    const pct=total?Math.round(done/total*100):0;
    const bar=document.getElementById('clp-'+clId); if(bar)bar.style.width=pct+'%';
    const hdr=document.querySelector(`#cl-${clId} .cl-hdr-t span`); if(hdr)hdr.textContent=`${done}/${total}`;
}

/* ─── COMMENTS ─── */
function renderComments(cmts){ const c=document.getElementById('mComments'); c.innerHTML=''; cmts.forEach(cmt=>appendCmt(cmt)); }
function appendCmt(cmt){
    const div=document.createElement('div'); div.className='cmt'; div.id='cmt-'+cmt.id;
    const dt=new Date(cmt.created_at); const ts=dt.toLocaleString('fr-FR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
    div.innerHTML=`
        <div class="cmt-av">${cmt.author.slice(0,2).toUpperCase()}</div>
        <div class="cmt-body">
            <div class="cmt-who">${esc(cmt.author)} <span class="cmt-when">${ts}</span></div>
            <div class="cmt-txt">${esc(cmt.content).replace(/\n/g,'<br>')}
                <button class="cmt-del" onclick="delCmt(${cmt.id})"><i class="fas fa-times"></i></button>
            </div>
        </div>
    `;
    document.getElementById('mComments').appendChild(div);
}
async function submitCmt(){
    const inp=document.getElementById('cmtIn'); const content=inp.value.trim(); if(!content||!curCard)return;
    const res=await api({action:'add_comment',card_id:curCard,content});
    if(res.success){ appendCmt(res); inp.value=''; }
}
async function delCmt(id){ await api({action:'delete_comment',id}); document.getElementById('cmt-'+id)?.remove(); }

/* ─── UTILS ─── */
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function autoH(el){ el.style.height='auto'; el.style.height=el.scrollHeight+'px'; }

// Init
document.getElementById('myAv').textContent=ME.slice(0,2).toUpperCase();

/* ══════════════════════════════════════════════════
   TEMPS RÉEL — Polling toutes les 6 secondes
   Compare un hash serveur ; si différent, applique
   les changements sans recharger la page.
══════════════════════════════════════════════════ */
const POLL_INTERVAL = 6000;
let   lastHash      = '';
let   pollTimer     = null;
let   pollPaused    = false; // pause quand une modal est ouverte (drag, édition)

const CODE_TEN_COLORS_RT  = <?= json_encode($CODE_TEN) ?>;
const OTHER_TAG_COLORS_RT = <?= json_encode($OTHER_TAGS) ?>;

function rtBuildCardHTML(c) {
    const prio      = c.priority || '';
    const cardColor = c.card_color || '';
    let   style     = '', dataBg = '';
    if (cardColor) {
        const r=parseInt(cardColor.slice(1,3),16), g=parseInt(cardColor.slice(3,5),16), b=parseInt(cardColor.slice(5,7),16);
        style   = ` style="background:rgba(${r},${g},${b},0.18);border-color:rgba(${r},${g},${b},0.35);"`;
        dataBg  = ` data-bg="${cardColor}"`;
    }
    let html = `<div class="dcard" id="card-${c.id}" data-card-id="${c.id}" data-p="${prio}" data-title="${esc(c.title||'').toLowerCase()}"${dataBg} onclick="openCard(${c.id})"${style}>`;

    // cover
    if (c.cover_url) {
        html += `<div class="card-cover-wrap"><img class="card-cover" src="${esc(c.cover_url)}" alt="" onclick="event.stopPropagation();openLbx('${esc(c.cover_url)}')" title="Agrandir"><div class="card-cover-ttl">${esc(c.title)}</div><span class="card-zoom-btn" onclick="event.stopPropagation();openLbx('${esc(c.cover_url)}')"><i class="fas fa-expand-alt"></i></span></div>`;
    }

    html += '<div class="card-body">';

    // labels
    if (c.labels && c.labels.length) {
        html += '<div class="card-lbls">';
        c.labels.forEach(l => { html += `<span class="card-lbl" style="background:${esc(l.color)}">${esc(l.name)}</span>`; });
        html += '</div>';
    }

    if (!c.cover_url) html += `<div class="card-title">${esc(c.title)}</div>`;

    // description preview
    if (c.description) {
        const short = c.description.length > 120 ? c.description.slice(0,120)+'…' : c.description;
        html += `<div class="card-desc-prev">${esc(short)}</div>`;
    }

    // tags
    let tags = '';
    (c.grade_tags||[]).forEach(g => { tags += `<span class="ctag ct-grade"><i class="fas fa-shield-alt" style="font-size:.5rem"></i> ${esc(g)}</span>`; });
    if (c.patrol_vehicle) {
        const platePart = c.vehicle_plate ? ` · ${esc(c.vehicle_plate)}` : '';
        tags += `<span class="ctag ct-veh"><i class="fas fa-car" style="font-size:.5rem"></i> ${esc(c.patrol_vehicle)}${platePart}</span>`;
    }
    (c.code_ten_tags||[]).forEach(t => { const col=CODE_TEN_COLORS_RT[t]||'gray'; tags += `<span class="ctag ct-${col}">📡 ${esc(t)}</span>`; });
    (c.other_tags||[]).forEach(t  => { const col=OTHER_TAG_COLORS_RT[t]||'gray';  tags += `<span class="ctag ct-${col}">${esc(t)}</span>`; });
    if (tags) html += `<div class="card-tags">${tags}</div>`;

    // footer
    html += '<div class="card-foot">';
    if (prio) html += `<span class="prio-lbl ${prio}">${prio.toUpperCase()}</span>`;
    if (c.due_date) {
        const due=new Date(c.due_date).getTime()/1000, now=Date.now()/1000, diff=due-now;
        if (diff<0)        html += `<span class="cfoot-badge ov"><i class="fas fa-clock"></i> Retard</span>`;
        else if (diff<7200) html += `<span class="cfoot-badge ds"><i class="fas fa-clock"></i> ${new Date(due*1000).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'})}</span>`;
        else               html += `<span class="cfoot-badge"><i class="fas fa-clock"></i> ${new Date(due*1000).toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit'})} ${new Date(due*1000).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'})}</span>`;
    }
    if (c.assigned_to) html += `<span class="cfoot-badge"><i class="fas fa-user"></i> ${esc(c.assigned_to)}</span>`;
    if (c.location)    html += `<span class="cfoot-badge"><i class="fas fa-map-marker-alt"></i> ${esc(c.location)}</span>`;
    html += '</div></div></div>';
    return html;
}

function rtApply(data) {
    const dot = document.getElementById('syncDot');

    // ── Colonnes : ajout/suppression/renommage
    const board    = document.getElementById('boardScroll');
    const addBtn   = board.querySelector('.add-list-btn');
    const existIds = new Set([...board.querySelectorAll('.list-col')].map(c => c.dataset.lid));
    const newIds   = new Set(data.lists.map(l => String(l.id)));

    // Supprimer les colonnes disparues
    existIds.forEach(id => { if (!newIds.has(id)) document.getElementById('col-'+id)?.remove(); });

    // Ajouter / mettre à jour les colonnes
    data.lists.forEach((l, idx) => {
        const lid  = String(l.id);
        let   col  = document.getElementById('col-'+lid);
        const lc   = l.color || '#00e5ff';
        if (!col) {
            // Nouvelle colonne
            col = document.createElement('div');
            col.className = 'list-col';
            col.id = 'col-'+lid;
            col.dataset.lid = lid;
            col.innerHTML = `
                <div class="col-hdr" style="border-color:${lc}33;">
                    <div class="col-dot" style="background:${lc};box-shadow:0 0 7px ${lc}66;"></div>
                    <input class="col-name" type="text" value="${esc(l.name)}"
                           onchange="saveListName(${l.id},this.value,'${lc}')"
                           onclick="event.stopPropagation()" onmousedown="event.stopPropagation()">
                    <span class="col-cnt" id="cnt-${lid}">0</span>
                    <button class="col-more" onclick="openCtx(event,${l.id})"><i class="fas fa-ellipsis-h"></i></button>
                </div>
                <div class="cards-wrap" id="cw-${lid}"></div>
                <div id="acf-${lid}" class="add-card-form" style="display:none;">
                    <textarea placeholder="Titre de la carte..." rows="3" onkeydown="acfKey(event,${l.id})"></textarea>
                    <div class="acf-row">
                        <button class="btn btn-p btn-sm" onclick="doAddCard(${l.id})"><i class="fas fa-plus"></i> Ajouter</button>
                        <button class="btn btn-g btn-sm" onclick="cancelAcf(${l.id})"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <button class="add-card-row" id="acb-${lid}" onclick="showAcf(${l.id})"><i class="fas fa-plus"></i> Ajouter une carte</button>`;
            currentColColors[l.id] = lc;
            board.insertBefore(col, addBtn);
        } else {
            // Mettre à jour couleur/nom si changé
            const dot  = col.querySelector('.col-dot');
            const inp  = col.querySelector('.col-name');
            if (dot && dot.style.background !== lc) { dot.style.cssText=`background:${lc};box-shadow:0 0 7px ${lc}66;`; col.querySelector('.col-hdr').style.borderColor=lc+'33'; currentColColors[l.id]=lc; }
            if (inp && inp !== document.activeElement && inp.value !== l.name) inp.value = l.name;
        }
    });

    // ── Cartes : diff par colonne
    data.lists.forEach(l => {
        const lid = String(l.id);
        const cw  = document.getElementById('cw-'+lid);
        if (!cw) return;

        const serverCards = data.cards.filter(c => String(c.list_id) === lid).sort((a,b)=>a.position-b.position);
        const serverIds   = serverCards.map(c => String(c.id));
        const localIds    = [...cw.querySelectorAll('.dcard')].map(el => el.dataset.cardId);

        // Supprimer les cartes disparues (sauf si elle est ouverte dans la modal)
        localIds.forEach(id => {
            if (!serverIds.includes(id) && String(curCard) !== id) {
                document.getElementById('card-'+id)?.remove();
            }
        });

        // Ajouter / mettre à jour les cartes
        serverCards.forEach((c, idx) => {
            const cid = String(c.id);
            const el  = document.getElementById('card-'+cid);

            if (String(curCard) === cid) return; // carte ouverte → pas de maj DOM

            const newHTML = rtBuildCardHTML(c);
            if (!el) {
                // Nouvelle carte : insérer à la bonne position
                const tmp = document.createElement('div');
                tmp.innerHTML = newHTML;
                const next = cw.querySelectorAll('.dcard')[idx];
                cw.insertBefore(tmp.firstElementChild, next || null);
            } else {
                // Carte existante : MAJ seulement si le contenu a changé (updated_at différent)
                if (el.dataset.updatedAt !== c.updated_at) {
                    const tmp = document.createElement('div');
                    tmp.innerHTML = newHTML;
                    const newEl = tmp.firstElementChild;
                    newEl.dataset.updatedAt = c.updated_at;
                    el.replaceWith(newEl);
                }
            }
        });

        // Mettre à jour le compteur
        updateCnt(lid);
    });

    // Pulse du dot de sync
    if (dot) {
        dot.classList.remove('err');
        dot.classList.add('pulse');
        setTimeout(() => dot.classList.remove('pulse'), 700);
    }
}

async function pollBoard() {
    if (pollPaused) return;
    const dot = document.getElementById('syncDot');
    try {
        const r = await fetch(location.href + '?ajax=1&action=poll_board');
        const data = await r.json();
        if (!data.success) return;
        if (data.hash !== lastHash) {
            lastHash = data.hash;
            rtApply(data);
        }
        if (dot) dot.classList.remove('err');
    } catch(e) {
        if (dot) dot.classList.add('err');
    }
}

// Lancer le polling
(function startPoll(){
    // Récupérer le hash initial sans modifier le DOM
    fetch(location.href+'?ajax=1&action=poll_board')
        .then(r=>r.json())
        .then(d=>{ if(d.success) lastHash=d.hash; })
        .catch(()=>{});
    pollTimer = setInterval(pollBoard, POLL_INTERVAL);
})();

// Pause polling quand une carte est ouverte (évite conflits pendant l'édition)
const _origOpenCard  = openCard;
window.openCard = function(id){ pollPaused=true;  _origOpenCard(id); };
const _origCloseCard = closeCard;
window.closeCard = function(){ _origCloseCard(); pollPaused=false; pollBoard(); };
</script>

<!-- ══ MODALE OFFICIERS EN SERVICE ══ -->
<div class="mov hidden" id="officerModal" onclick="if(event.target===this)closeOfficerPicker()">
<div class="mbox" style="max-width:640px;">
  <div class="m-top" style="padding:1.1rem;">
    <div class="m-ico"><i class="fas fa-user-shield"></i></div>
    <h3 style="font-family:'Orbitron',monospace;font-size:.82rem;font-weight:700;color:var(--tx);letter-spacing:1px;margin:0;">OFFICIERS EN SERVICE</h3>
    <button class="m-x" onclick="closeOfficerPicker()"><i class="fas fa-times"></i></button>
  </div>
  <div style="padding:.85rem 1.1rem;">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.7rem;">
      <input class="minp" id="ofcSearch" placeholder="🔎 Rechercher (nom, matricule)..." style="flex:1;min-width:160px;" oninput="renderOfcList()">
      <select class="msel" id="ofcGradeFilter" style="width:150px;" onchange="renderOfcList()"><option value="">Tous grades</option></select>
      <select class="msel" id="ofcDivFilter" style="width:150px;" onchange="renderOfcList()"><option value="">Toutes divisions</option></select>
    </div>
    <div id="ofcList" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:.5rem;max-height:46vh;overflow-y:auto;"></div>
  </div>
  <div style="padding:.85rem 1.1rem;border-top:1px solid rgba(255,255,255,.07);display:flex;align-items:center;justify-content:space-between;gap:.7rem;">
    <span style="font-size:.78rem;color:var(--mu);"><span id="ofcCount">0</span> agent(s) sélectionné(s)</span>
    <div style="display:flex;gap:.5rem;">
      <button class="btn btn-sm" onclick="closeOfficerPicker()">Annuler</button>
      <button class="btn btn-p btn-sm" onclick="confirmOfficerPicker()"><i class="fas fa-check"></i> Valider</button>
    </div>
  </div>
</div></div>

<!-- ══ MODALE COVER CDN ══ -->
<div class="mov hidden" id="coverCdnModal" onclick="if(event.target===this)closeCoverCdn()">
<div class="mbox" style="max-width:680px;">
  <div class="m-top" style="padding:1.1rem;">
    <div class="m-ico"><i class="fas fa-images"></i></div>
    <h3 style="font-family:'Orbitron',monospace;font-size:.82rem;font-weight:700;color:var(--tx);letter-spacing:1px;margin:0;">CDN LSPD — IMAGE DE COUVERTURE</h3>
    <button class="m-x" onclick="closeCoverCdn()"><i class="fas fa-times"></i></button>
  </div>
  <div style="padding:.85rem 1.1rem;">
    <input class="minp" id="cdnSearch" placeholder="🔎 Rechercher une image..." style="width:100%;margin-bottom:.7rem;" oninput="renderCdnGallery(this.value)">
    <div id="cdnGalleryGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.5rem;max-height:48vh;overflow-y:auto;"></div>
  </div>
  <div style="padding:.85rem 1.1rem;border-top:1px solid rgba(255,255,255,.07);display:flex;justify-content:flex-end;gap:.5rem;">
    <button class="btn btn-sm" onclick="closeCoverCdn()">Annuler</button>
    <button class="btn btn-p btn-sm" id="cdnValidBtn" disabled onclick="confirmCoverCdn()"><i class="fas fa-check"></i> Utiliser cette image</button>
  </div>
</div></div>

<!-- ══ MODALE NOUVELLE CHECKLIST ══ -->
<div class="mov hidden" id="clTitleModal" onclick="if(event.target===this)closeClTitle()">
<div class="mbox" style="max-width:420px;">
  <div class="m-top" style="padding:1.1rem;">
    <div class="m-ico"><i class="fas fa-tasks"></i></div>
    <h3 style="font-family:'Orbitron',monospace;font-size:.82rem;font-weight:700;color:var(--tx);letter-spacing:1px;margin:0;">NOUVELLE CHECKLIST</h3>
    <button class="m-x" onclick="closeClTitle()"><i class="fas fa-times"></i></button>
  </div>
  <div style="padding:1rem 1.1rem;">
    <div class="m-label" style="margin-bottom:.4rem;"><i class="fas fa-pen"></i> Titre de la checklist</div>
    <input class="minp" id="clTitleInput" placeholder="Ex: Procédure d'intervention..." style="width:100%;" onkeydown="if(event.key==='Enter'){event.preventDefault();confirmAddCL();}">
  </div>
  <div style="padding:0 1.1rem 1.1rem;display:flex;justify-content:flex-end;gap:.5rem;">
    <button class="btn btn-sm" onclick="closeClTitle()">Annuler</button>
    <button class="btn btn-p btn-sm" onclick="confirmAddCL()"><i class="fas fa-plus"></i> Créer</button>
  </div>
</div></div>

<!-- ══ MODALE CODE TEN / TAGS STATUT ══ -->
<div class="mov hidden" id="tagPickerModal" onclick="if(event.target===this)closeTagPicker()">
<div class="mbox" style="max-width:600px;">
  <div class="m-top" style="padding:1.1rem;">
    <div class="m-ico"><i class="fas fa-tower-broadcast" id="tagPickerIcon"></i></div>
    <h3 id="tagPickerTitle" style="font-family:'Orbitron',monospace;font-size:.82rem;font-weight:700;color:var(--tx);letter-spacing:1px;margin:0;">CODE TEN</h3>
    <button class="m-x" onclick="closeTagPicker()"><i class="fas fa-times"></i></button>
  </div>
  <div style="padding:.85rem 1.1rem;">
    <input class="minp" id="tagPickerSearch" placeholder="🔎 Rechercher un code, une description..." style="width:100%;margin-bottom:.7rem;" oninput="renderTagPicker()">
    <div id="tagPickerGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.5rem;max-height:50vh;overflow-y:auto;"></div>
  </div>
  <div style="padding:.85rem 1.1rem;border-top:1px solid rgba(255,255,255,.07);display:flex;align-items:center;justify-content:space-between;gap:.7rem;">
    <span style="font-size:.78rem;color:var(--mu);"><span id="tagPickerCount">0</span> sélectionné(s)</span>
    <button class="btn btn-p btn-sm" onclick="closeTagPicker()"><i class="fas fa-check"></i> Terminé</button>
  </div>
</div></div>

<script>
/* ═══════════ OFFICIERS / SPÉCIALITÉS AUTO ═══════════ */
var selectedAgents = [];
var DUTY_AGENTS = [], ofcStaged = [];
function _ofcEsc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
function _ofcAvatar(a,sz){ sz=sz||30; return a.avatar?`<img src="${_ofcEsc(a.avatar)}" style="width:${sz}px;height:${sz}px;border-radius:50%;object-fit:cover;flex-shrink:0">`:`<span style="width:${sz}px;height:${sz}px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:grid;place-items:center;color:#fff;font-weight:700;font-size:${Math.round(sz*0.4)}px;flex-shrink:0">${_ofcEsc((a.name||'?').slice(0,1).toUpperCase())}</span>`; }

function renderAgentChips(){
    const wrap=document.getElementById('mAgentsChips'); if(!wrap)return;
    wrap.innerHTML=(selectedAgents||[]).map((a,i)=>`<span style="display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .5rem .25rem .3rem;background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.3);border-radius:20px;font-size:.74rem;">${_ofcAvatar(a,22)}<span><strong>${_ofcEsc(a.name)}</strong>${a.matricule?` <span style="color:#93c5fd;font-family:'Chakra Petch',monospace">#${_ofcEsc(a.matricule)}</span>`:''}${a.grade?` <span style="color:var(--mu);font-size:.66rem">${_ofcEsc(a.grade)}</span>`:''}</span><i class="fas fa-times" style="cursor:pointer;color:var(--mu);font-size:.7rem" onclick="removeAgent(${i})"></i></span>`).join('');
    // mAssigned (texte rétro-compatible)
    document.getElementById('mAssigned').value=(selectedAgents||[]).map(a=>a.name+(a.matricule?' #'+a.matricule:'')).join(', ');
    // Spécialités auto (divisions)
    const specs=[...new Set((selectedAgents||[]).flatMap(a=>String(a.divisions||'').split(',').map(s=>s.trim()).filter(Boolean)))];
    const gw=document.getElementById('mGrades'); if(gw) gw.innerHTML=specs.map(s=>`<span class="ctag ct-grade"><i class="fas fa-shield-alt" style="font-size:.5rem"></i> ${_ofcEsc(s)}</span>`).join('');
    const ge=document.getElementById('mGradesEmpty'); if(ge) ge.style.display=specs.length?'none':'block';
}
function removeAgent(i){ selectedAgents.splice(i,1); renderAgentChips(); }

async function openOfficerPicker(){
    if(!curCard)return;
    ofcStaged = JSON.parse(JSON.stringify(selectedAgents||[]));
    document.getElementById('officerModal').classList.remove('hidden');
    document.getElementById('ofcList').innerHTML='<div style="grid-column:1/-1;color:var(--mu);padding:1rem;text-align:center">Chargement…</div>';
    const r=await apiGet({action:'get_duty_agents'});
    DUTY_AGENTS=(r&&r.agents)||[];
    const gf=document.getElementById('ofcGradeFilter'); gf.innerHTML='<option value="">Tous grades</option>'+((r&&r.grades)||[]).map(g=>`<option value="${_ofcEsc(g)}">${_ofcEsc(g)}</option>`).join('');
    const df=document.getElementById('ofcDivFilter'); df.innerHTML='<option value="">Toutes divisions</option>'+((r&&r.divisions)||[]).map(d=>`<option value="${_ofcEsc(d)}">${_ofcEsc(d)}</option>`).join('');
    renderOfcList();
}
function closeOfficerPicker(){ document.getElementById('officerModal').classList.add('hidden'); }
function renderOfcList(){
    const q=(document.getElementById('ofcSearch').value||'').toLowerCase().trim();
    const gf=document.getElementById('ofcGradeFilter').value, df=document.getElementById('ofcDivFilter').value;
    const list=DUTY_AGENTS.filter(a=>{
        if(q && !((a.name||'').toLowerCase().includes(q) || String(a.matricule||'').toLowerCase().includes(q))) return false;
        if(gf && a.grade!==gf) return false;
        if(df && !String(a.divisions||'').split(',').map(s=>s.trim()).includes(df)) return false;
        return true;
    });
    const box=document.getElementById('ofcList');
    if(!list.length){ box.innerHTML='<div style="grid-column:1/-1;color:var(--mu);padding:1.2rem;text-align:center">Aucun agent en service.</div>'; document.getElementById('ofcCount').textContent=ofcStaged.length; return; }
    box.innerHTML=list.map(a=>{
        const sel=ofcStaged.some(x=>x.id===a.id);
        return `<div onclick="toggleOfc(${a.id})" style="display:flex;align-items:center;gap:.6rem;padding:.55rem .65rem;border:1px solid ${sel?'rgba(59,130,246,.55)':'rgba(255,255,255,.08)'};background:${sel?'rgba(59,130,246,.14)':'rgba(255,255,255,.02)'};border-radius:10px;cursor:pointer;transition:all .15s">
            ${_ofcAvatar(a,38)}
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${_ofcEsc(a.name)}</div>
                <div style="font-size:.68rem;color:var(--mu)">${a.matricule?`#${_ofcEsc(a.matricule)} · `:''}${_ofcEsc(a.grade||'—')}</div>
                ${a.divisions?`<div style="font-size:.62rem;color:#93c5fd;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${_ofcEsc(a.divisions)}</div>`:''}
            </div>
            <i class="fas ${sel?'fa-circle-check':'fa-circle'}" style="color:${sel?'#60a5fa':'var(--mu)'};font-size:1rem"></i>
        </div>`;
    }).join('');
    document.getElementById('ofcCount').textContent=ofcStaged.length;
}
function toggleOfc(id){
    const i=ofcStaged.findIndex(x=>x.id===id);
    if(i>=0) ofcStaged.splice(i,1);
    else { const a=DUTY_AGENTS.find(x=>x.id===id); if(a) ofcStaged.push(a); }
    renderOfcList();
}
function confirmOfficerPicker(){ selectedAgents=JSON.parse(JSON.stringify(ofcStaged)); renderAgentChips(); closeOfficerPicker(); }

/* ═══════════ HISTORIQUE LIVE ═══════════ */
function _fmtLogDate(s){ if(!s)return''; const d=new Date(String(s).replace(' ','T')); if(isNaN(d))return s; return d.toLocaleDateString('fr-FR')+' '+d.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}); }
async function loadCardHistory(){
    const box=document.getElementById('cardHistory'); if(!box||!curCard)return;
    box.innerHTML='<div style="color:var(--mu);padding:.4rem 0">Chargement…</div>';
    const r=await apiGet({action:'get_card_logs',card_id:curCard});
    const logs=(r&&r.logs)||[];
    if(!logs.length){ box.innerHTML='<div style="color:var(--mu);padding:.4rem 0;font-style:italic">Aucun historique.</div>'; return; }
    box.innerHTML=logs.map(l=>`<div style="display:flex;gap:.5rem;padding:.45rem 0;border-bottom:1px solid rgba(255,255,255,.05)">${_ofcAvatar({name:l.name,avatar:l.avatar},24)}<div style="flex:1;min-width:0"><div style="line-height:1.4"><strong>${_ofcEsc(l.name)}</strong> ${_ofcEsc(l.detail||l.action)}</div><div style="color:var(--mu);font-size:.64rem;font-family:'Chakra Petch',monospace">${_fmtLogDate(l.at)}</div></div></div>`).join('');
}

/* ═══════════ COVER DEPUIS LE CDN ═══════════ */
var CDN_IMAGES=[], cdnPicked='';
async function openCoverCdn(){
    if(!curCard)return;
    document.getElementById('coverCdnModal').classList.remove('hidden');
    document.getElementById('cdnValidBtn').disabled=true; cdnPicked='';
    const grid=document.getElementById('cdnGalleryGrid'); grid.innerHTML='<div style="grid-column:1/-1;color:var(--mu);text-align:center;padding:1rem">Chargement…</div>';
    try{
        const r=await fetch('archive.php?cdn_action=list',{headers:{'X-Requested-With':'XMLHttpRequest'}});
        const d=await r.json(); CDN_IMAGES=(d.images||[]).filter(x=>x.url); renderCdnGallery('');
    }catch(e){ grid.innerHTML='<div style="grid-column:1/-1;color:#f87171;text-align:center;padding:1rem">Erreur de chargement du CDN</div>'; }
}
function closeCoverCdn(){ document.getElementById('coverCdnModal').classList.add('hidden'); }
function renderCdnGallery(q){
    q=(q||'').toLowerCase().trim();
    const list=CDN_IMAGES.filter(im=>!q||(im.title||'').toLowerCase().includes(q));
    const grid=document.getElementById('cdnGalleryGrid');
    if(!list.length){ grid.innerHTML='<div style="grid-column:1/-1;color:var(--mu);text-align:center;padding:1rem">Aucune image.</div>'; return; }
    grid.innerHTML=list.map(im=>`<div onclick="pickCdn('${_ofcEsc(im.url)}',this)" style="aspect-ratio:1;border-radius:9px;background:#020508 center/cover no-repeat;background-image:url('${_ofcEsc(im.url)}');border:2px solid ${cdnPicked===im.url?'#00e5ff':'transparent'};cursor:pointer" title="${_ofcEsc(im.title||'')}"></div>`).join('');
}
function pickCdn(url,el){ cdnPicked=url; document.querySelectorAll('#cdnGalleryGrid > div').forEach(d=>d.style.borderColor='transparent'); el.style.borderColor='#00e5ff'; document.getElementById('cdnValidBtn').disabled=false; }
async function confirmCoverCdn(){
    if(!cdnPicked||!curCard)return;
    const r=await api({action:'set_cover_url',card_id:curCard,url:cdnPicked});
    if(r&&r.success){
        document.getElementById('coverPreviewImg').src=r.url;
        document.getElementById('coverPreviewWrap').style.display='block';
        document.getElementById('coverDrop').style.display='none';
        closeCoverCdn(); toast('Image de couverture définie'); updateCardDOM(); loadCardHistory();
    } else toast((r&&r.error)||'Erreur','e');
}

/* ═══════════ PICKER CODE TEN / TAGS STATUT (boutons + recherche + couleurs) ═══════════ */
var TAG_PICK_KIND='ten';
var TAG_COLOR_HEX={gray:'#94a3b8',green:'#22c55e',red:'#ef4444',orange:'#f97316',blue:'#60a5fa',purple:'#a78bfa',yellow:'#eab308'};
var TEN_DESC={
    "10-03":"Stop radio / silence","10-06":"Occupé","10-07":"Hors service","10-08":"En service — disponible",
    "10-10":"En pause","10-13":"Conditions / météo","10-14":"Escorte / convoi","10-15":"Transport de prisonnier",
    "10-19":"Retour au poste","10-20":"Position / localisation","10-23":"Sur les lieux","10-31":"Crime en cours",
    "10-35":"Information confidentielle","10-38":"Contrôle de véhicule suspect","10-40":"Intervention silencieuse",
    "10-50":"Accident de la route","10-51":"Demande de dépanneuse","10-52":"Demande d'ambulance",
    "10-56":"Conducteur en état d'ivresse","10-57":"Délit de fuite","10-59":"Escorte de sécurité",
    "10-60":"Suspect en vue","10-70":"Incendie","10-74":"Négatif","10-75":"En contact avec…",
    "10-80":"Poursuite en cours","10-90":"Alarme déclenchée","10-91":"Animal","10-98":"Mission terminée"
};
function _tagOptsId(){ return TAG_PICK_KIND==='ten'?'tenOpts':'otherOpts'; }
function _tagColorMap(){ try{ return TAG_PICK_KIND==='ten'?CODE_TEN_COLORS:OTHER_TAG_COLORS; }catch(e){ return {}; } }
function openTagPicker(kind){
    TAG_PICK_KIND=kind;
    document.getElementById('tagPickerTitle').textContent = kind==='ten'?'CODE TEN':'TAGS STATUT';
    document.getElementById('tagPickerIcon').className = kind==='ten'?'fas fa-tower-broadcast':'fas fa-tag';
    document.getElementById('tagPickerSearch').value='';
    document.getElementById('tagPickerModal').classList.remove('hidden');
    renderTagPicker();
}
function closeTagPicker(){ document.getElementById('tagPickerModal').classList.add('hidden'); }
function renderTagPicker(){
    const q=(document.getElementById('tagPickerSearch').value||'').toLowerCase().trim();
    const colorMap=_tagColorMap();
    const cbs=[...document.querySelectorAll('#'+_tagOptsId()+' input[type=checkbox]')];
    const count=cbs.filter(c=>c.checked).length;
    const items=cbs.filter(c=>{ const desc=(TAG_PICK_KIND==='ten'?(TEN_DESC[c.value]||''):'').toLowerCase(); return !q||c.value.toLowerCase().includes(q)||desc.includes(q); });
    const grid=document.getElementById('tagPickerGrid');
    if(!items.length){ grid.innerHTML='<div style="grid-column:1/-1;color:var(--mu);text-align:center;padding:1rem">Aucun résultat.</div>'; document.getElementById('tagPickerCount').textContent=count; return; }
    grid.innerHTML=items.map(c=>{
        const code=c.value, col=colorMap[code]||'gray', hex=TAG_COLOR_HEX[col]||'#94a3b8', on=c.checked;
        const desc=TAG_PICK_KIND==='ten'?(TEN_DESC[code]||''):'';
        return `<button type="button" data-code="${_ofcEsc(code)}" onclick="toggleTagPick(this.dataset.code)" style="display:flex;flex-direction:column;align-items:flex-start;gap:.25rem;padding:.6rem .7rem;border-radius:10px;border:1px solid ${on?hex:'rgba(255,255,255,.08)'};background:${on?hex+'22':'rgba(255,255,255,.02)'};cursor:pointer;text-align:left;transition:all .15s;">
            <span style="display:flex;align-items:center;gap:.4rem;font-weight:700;font-size:.82rem;color:${on?hex:'var(--tx)'};"><span style="width:9px;height:9px;border-radius:50%;background:${hex};flex-shrink:0;box-shadow:0 0 6px ${hex}88"></span>${TAG_PICK_KIND==='ten'?'📡 ':''}${_ofcEsc(code)}${on?' <i class="fas fa-check" style="font-size:.7rem;margin-left:auto"></i>':''}</span>
            ${desc?`<span style="font-size:.66rem;color:var(--mu);line-height:1.35">${_ofcEsc(desc)}</span>`:''}
        </button>`;
    }).join('');
    document.getElementById('tagPickerCount').textContent=count;
}
function toggleTagPick(code){
    const cb=[...document.querySelectorAll('#'+_tagOptsId()+' input[type=checkbox]')].find(c=>c.value===code);
    if(cb){ cb.checked=!cb.checked; updateTagSel(TAG_PICK_KIND==='ten'?'ten':'other'); renderTagPicker(); }
}

/* ═══════════ NOUVELLE CHECKLIST (modale, remplace prompt natif) ═══════════ */
function closeClTitle(){ document.getElementById('clTitleModal').classList.add('hidden'); }
async function confirmAddCL(){
    const t=document.getElementById('clTitleInput').value.trim(); if(!t)return;
    closeClTitle();
    const res=await api({action:'add_checklist',card_id:curCard,title:t});
    if(res.success){ appendCL({id:res.id,title:t,items:[]}); toast('Checklist ajoutée !'); }
    else toast((res&&res.error)||'Erreur','e');
}
</script>

<?php include __DIR__.'/includes/footer.php'; ?>