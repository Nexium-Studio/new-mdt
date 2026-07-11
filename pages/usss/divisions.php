<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$companyName = '🇺🇸 United States Secret Service';
$companyColor = '#1e3a8a';
$companyFaction = 'USSS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Gestion des Divisions';
$db = Database::getInstance()->getConnection();
$message = ''; $msgType = '';

// Permissions
$isAdmin = hasRole(['*', 'Administrateur', 'Développeur']);

// ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = $_POST['action'] ?? '';
    
    // Créer division
    if ($action === 'create_division') {
        try {
            $code = strtoupper(sanitize($_POST['code']));
            
            // Vérifier unicité code
            $exists = $db->query("SELECT id FROM usss_divisions WHERE code = '$code'")->fetch();
            if ($exists) {
                throw new Exception("Ce code de division existe déjà");
            }
            
            $db->prepare("
                INSERT INTO usss_divisions 
                (nom, code, description, chef_division, budget_alloue, niveau_securite, ordre, actif)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ")->execute([
                sanitize($_POST['nom']),
                $code,
                sanitize($_POST['description']),
                !empty($_POST['chef_division']) ? (int)$_POST['chef_division'] : null,
                !empty($_POST['budget_alloue']) ? (float)$_POST['budget_alloue'] : 0,
                $_POST['niveau_securite'] ?? 'CONFIDENTIEL',
                (int)($_POST['ordre'] ?? 99)
            ]);
            
            $message = "✅ Division créée avec succès";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Modifier division
    if ($action === 'update_division') {
        try {
            $divisionId = (int)$_POST['division_id'];
            
            $db->prepare("
                UPDATE usss_divisions 
                SET nom = ?, description = ?, chef_division = ?, budget_alloue = ?, 
                    niveau_securite = ?, ordre = ?
                WHERE id = ?
            ")->execute([
                sanitize($_POST['nom']),
                sanitize($_POST['description']),
                !empty($_POST['chef_division']) ? (int)$_POST['chef_division'] : null,
                !empty($_POST['budget_alloue']) ? (float)$_POST['budget_alloue'] : 0,
                $_POST['niveau_securite'],
                (int)$_POST['ordre'],
                $divisionId
            ]);
            
            $message = "✅ Division modifiée avec succès";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Désactiver/Activer division
    if ($action === 'toggle_division') {
        try {
            $divisionId = (int)$_POST['division_id'];
            $db->prepare("UPDATE usss_divisions SET actif = NOT actif WHERE id = ?")
               ->execute([$divisionId]);
            
            $message = "✅ Statut de la division modifié";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Requête divisions avec stats
$divisions = $db->query("
    SELECT 
        d.*,
        chef.nom_rp as chef_nom,
        chef.prenom_rp as chef_prenom,
        chef.badge_number as chef_badge,
        COUNT(DISTINCT a.id) as effectif_total,
        COUNT(DISTINCT CASE WHEN a.statut = 'EN_SERVICE' THEN a.id END) as effectif_actif,
        COUNT(DISTINCT dos.id) as dossiers_en_cours,
        COUNT(DISTINCT o.id) as operations_actives
    FROM usss_divisions d
    LEFT JOIN usss_agents chef ON d.chef_division = chef.id
    LEFT JOIN usss_agents a ON d.id = a.division_id
    LEFT JOIN usss_dossiers dos ON d.id = dos.division_responsable AND dos.statut IN ('OUVERT', 'EN_COURS')
    LEFT JOIN usss_operations o ON d.id = o.division_responsable AND o.statut IN ('PLANIFIEE', 'EN_COURS')
    GROUP BY d.id
    ORDER BY d.ordre, d.nom
")->fetchAll();

// Agents disponibles pour chef
$agentsDisponibles = $db->query("
    SELECT id, nom_rp, prenom_rp, badge_number, grade
    FROM usss_agents
    WHERE statut = 'EN_SERVICE'
    ORDER BY grade DESC, nom_rp
")->fetchAll();

// Stats globales
$statsGlobales = [
    'total_divisions' => count($divisions),
    'divisions_actives' => count(array_filter($divisions, fn($d) => $d['actif'])),
    'effectif_total' => array_sum(array_column($divisions, 'effectif_total')),
    'budget_total' => array_sum(array_column($divisions, 'budget_alloue')),
];

$niveauSecuriteColors = [
    'PUBLIC' => '#64748b',
    'CONFIDENTIEL' => '#3b82f6',
    'SECRET' => '#f59e0b',
    'TOP_SECRET' => '#dc2626'
];

if (isset($_GET['msg'])) { $message=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }
$divisions=[];
try{$divisions=$db->query("SELECT d.*,(SELECT COUNT(*) FROM usss_agents a WHERE a.division_id=d.id AND a.statut='EN_SERVICE') as effectif,(SELECT nom_rp FROM usss_agents WHERE id=d.chef_division LIMIT 1) as chef_nom,(SELECT prenom_rp FROM usss_agents WHERE id=d.chef_division LIMIT 1) as chef_prenom FROM usss_divisions d ORDER BY d.ordre")->fetchAll();}catch(Exception $e){}
$agents=[];try{$agents=$db->query("SELECT id,nom_rp,prenom_rp,badge_number FROM usss_agents WHERE statut='EN_SERVICE' ORDER BY nom_rp")->fetchAll();}catch(Exception $e){}

$secCfg=['1'=>'Accès Standard','2'=>'Confidentiel','3'=>'Secret','4'=>'Top Secret'];

include __DIR__ . '/includes/header.php';
?>
<?php if($message):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $message;?></div><?php endif;?>

<div style="display:flex;justify-content:flex-end;margin-bottom:1.25rem">
  <?php if($isAdmin):?><button onclick="USSS.openModal('newDivModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Créer une Division</button><?php endif;?>
</div>

<?php if(empty($divisions)):?>
<div class="card" style="text-align:center;padding:3.5rem;color:var(--txt-m)"><i class="fas fa-sitemap" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucune division</div>
<?php else:?>
<div class="grid2">
<?php foreach($divisions as $div): $pct=min(round(($div['effectif']/max(1,$div['effectif_max']??10))*100),100); $secLbl=$secCfg[$div['niveau_securite']??'1']??'Standard';?>
<div style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;overflow:hidden;transition:border-color .2s" onmouseover="this.style.borderColor='var(--bdr-b)'" onmouseout="this.style.borderColor='var(--bdr)'">
  <!-- Header -->
  <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--bdr);background:rgba(26,86,219,.03)">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem">
      <div>
        <div style="font-family:'IBM Plex Mono',monospace;font-size:.72rem;color:var(--blue-l);margin-bottom:.18rem"><?php echo htmlspecialchars($div['code']);?></div>
        <div style="font-family:'Barlow Condensed',sans-serif;font-size:1.1rem;font-weight:700;letter-spacing:.04em"><?php echo htmlspecialchars($div['nom']);?></div>
      </div>
      <div style="display:flex;gap:.35rem;flex-shrink:0">
        <span class="badge <?php echo $div['actif']?'b-green':'b-slate';?>" style="font-size:.6rem"><?php echo $div['actif']?'Active':'Inactive';?></span>
        <?php if($div['niveau_securite']>=3):?><span class="cls-stamp <?php echo $div['niveau_securite']>=4?'cls-ts':'cls-secret';?>" style="font-size:.55rem"><?php echo $secLbl;?></span><?php endif;?>
      </div>
    </div>
  </div>
  <!-- Body -->
  <div style="padding:.9rem 1.25rem">
    <?php if($div['description']):?><div style="font-size:.78rem;color:var(--txt-s);margin-bottom:.85rem;line-height:1.55"><?php echo htmlspecialchars(mb_substr($div['description'],0,120));?></div><?php endif;?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.65rem;margin-bottom:.75rem">
      <div><div class="form-label">Chef de division</div><div style="font-size:.82rem;font-weight:500;margin-top:.15rem"><?php echo $div['chef_nom']?htmlspecialchars($div['chef_nom'].' '.$div['chef_prenom']):'<span style="color:var(--txt-m)">Non désigné</span>';?></div></div>
      <div><div class="form-label">Effectif</div>
        <div style="display:flex;align-items:center;gap:.5rem;margin-top:.15rem">
          <span class="mono" style="font-size:.95rem;font-weight:600;color:var(--blue-xl)"><?php echo $div['effectif'];?></span>
          <span style="font-size:.72rem;color:var(--txt-m)">agents</span>
        </div>
      </div>
    </div>
    <?php if($div['budget_alloue']??0):?>
    <div class="mono" style="font-size:.72rem;color:var(--gold-l);margin-bottom:.65rem"><i class="fas fa-coins" style="margin-right:.3rem;opacity:.7"></i>Budget : $<?php echo number_format($div['budget_alloue'],0);?></div>
    <?php endif;?>
    <div class="prog-bar"><div class="prog-fill" style="width:<?php echo $pct;?>%"></div></div>
    <div style="font-size:.62rem;color:var(--txt-m);margin-top:.2rem;text-align:right"><?php echo $pct;?>% de l'effectif cible</div>
  </div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<?php if($isAdmin):?>
<!-- MODAL CRÉER DIVISION -->
<div id="newDivModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-sitemap"></i> Créer une Division</h2><button class="modal-cl" onclick="USSS.closeModal('newDivModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_division">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Code *</label><input type="text" name="code" class="form-input" required placeholder="PPD, FIN…" style="text-transform:uppercase"></div>
    <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Chef de division</label>
      <select name="chef_division" class="form-select"><option value="">Aucun</option><?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>"><?php echo htmlspecialchars($a['nom_rp'].' '.$a['prenom_rp'].' ('.$a['badge_number'].')');?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Niveau de sécurité</label>
      <select name="niveau_securite" class="form-select"><?php foreach($secCfg as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Budget alloué ($)</label><input type="number" name="budget_alloue" class="form-input" min="0" step="1000" value="0"></div>
    <div class="form-group"><label class="form-label">Ordre d'affichage</label><input type="number" name="ordre" class="form-input" min="0" value="0"></div>
    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" style="min-height:75px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('newDivModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>
<?php endif;?>
<?php include __DIR__ . '/includes/footer.php'; ?>