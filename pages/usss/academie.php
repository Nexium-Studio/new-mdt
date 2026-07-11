<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$companyName = '🇺🇸 United States Secret Service';
$companyColor = '#1e3a8a';
$companyFaction = 'USSS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Académie & Formation';
$db = Database::getInstance()->getConnection();
$message = ''; $msgType = '';

// Permissions
$isAdmin = hasRole(['*', 'Administrateur']);

// ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Créer formation
    if ($action === 'create_formation' && $isAdmin) {
        try {
            $db->prepare("
                INSERT INTO usss_formations 
                (nom, type, specialisation, description, duree_heures, formateur_id, note_minimale, validite_mois)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                sanitize($_POST['nom']),
                $_POST['type'],
                !empty($_POST['specialisation']) ? sanitize($_POST['specialisation']) : null,
                sanitize($_POST['description']),
                (int)$_POST['duree_heures'],
                !empty($_POST['formateur_id']) ? (int)$_POST['formateur_id'] : null,
                (float)$_POST['note_minimale'],
                !empty($_POST['validite_mois']) ? (int)$_POST['validite_mois'] : null
            ]);
            
            $message = "✅ Formation créée avec succès";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Inscrire agent
    if ($action === 'inscrire_agent') {
        try {
            $formationId = (int)$_POST['formation_id'];
            $agentId = (int)$_POST['agent_id'];
            
            // Vérifier si déjà inscrit
            $exists = $db->query("SELECT id FROM usss_formations_resultats WHERE formation_id = $formationId AND agent_id = $agentId AND resultat = 'EN_COURS'")->fetch();
            if ($exists) {
                throw new Exception("L'agent est déjà inscrit à cette formation");
            }
            
            $db->prepare("
                INSERT INTO usss_formations_resultats 
                (formation_id, agent_id, date_formation)
                VALUES (?, ?, ?)
            ")->execute([
                $formationId,
                $agentId,
                $_POST['date_formation'] ?? date('Y-m-d')
            ]);
            
            $message = "✅ Agent inscrit à la formation";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Évaluer agent
    if ($action === 'evaluer_agent') {
        try {
            $db->beginTransaction();
            
            $resultatId = (int)$_POST['resultat_id'];
            $noteObtenue = (float)$_POST['note_obtenue'];
            
            // Récupérer formation
            $result = $db->query("SELECT f.note_minimale, f.validite_mois FROM usss_formations_resultats r JOIN usss_formations f ON r.formation_id = f.id WHERE r.id = $resultatId")->fetch();
            
            $resultat = $noteObtenue >= $result['note_minimale'] ? 'REUSSI' : 'ECHEC';
            $dateExpiration = null;
            
            if ($resultat === 'REUSSI' && $result['validite_mois']) {
                $dateExpiration = date('Y-m-d', strtotime('+' . $result['validite_mois'] . ' months'));
            }
            
            $db->prepare("
                UPDATE usss_formations_resultats 
                SET note_obtenue = ?, resultat = ?, remarques = ?, certificat_genere = 1, date_expiration = ?
                WHERE id = ?
            ")->execute([
                $noteObtenue,
                $resultat,
                sanitize($_POST['remarques'] ?? ''),
                $dateExpiration,
                $resultatId
            ]);
            
            // Si certification, ajouter aux spécialisations de l'agent
            if ($resultat === 'REUSSI') {
                $formation = $db->query("SELECT f.specialisation, r.agent_id FROM usss_formations_resultats r JOIN usss_formations f ON r.formation_id = f.id WHERE r.id = $resultatId")->fetch();
                
                if ($formation['specialisation']) {
                    $agent = $db->query("SELECT specialisations FROM usss_agents WHERE id = " . $formation['agent_id'])->fetch();
                    $specialisations = $agent['specialisations'] ? json_decode($agent['specialisations'], true) : [];
                    
                    if (!in_array($formation['specialisation'], $specialisations)) {
                        $specialisations[] = $formation['specialisation'];
                        $db->prepare("UPDATE usss_agents SET specialisations = ? WHERE id = ?")
                           ->execute([json_encode($specialisations), $formation['agent_id']]);
                    }
                }
            }
            
            $db->commit();
            $message = $resultat === 'REUSSI' ? "✅ Agent validé - Certification délivrée" : "❌ Agent en échec";
            $msgType = $resultat === 'REUSSI' ? 'success' : 'warning';
        } catch (Exception $e) {
            $db->rollBack();
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Formations
$formations = $db->query("
    SELECT 
        f.*,
        formateur.nom_rp as formateur_nom,
        formateur.prenom_rp as formateur_prenom,
        COUNT(r.id) as nb_inscrits
    FROM usss_formations f
    LEFT JOIN usss_agents formateur ON f.formateur_id = formateur.id
    LEFT JOIN usss_formations_resultats r ON f.id = r.formation_id
    WHERE f.actif = 1
    GROUP BY f.id
    ORDER BY f.created_at DESC
")->fetchAll();

// Agents
$agents = $db->query("SELECT id, nom_rp, prenom_rp, badge_number FROM usss_agents WHERE statut = 'EN_SERVICE' ORDER BY nom_rp")->fetchAll();

// Stats
$stats = [
    'total_formations' => count($formations),
    'agents_formes' => $db->query("SELECT COUNT(DISTINCT agent_id) FROM usss_formations_resultats WHERE resultat = 'REUSSI'")->fetchColumn(),
    'certifications' => $db->query("SELECT COUNT(*) FROM usss_formations_resultats WHERE resultat = 'REUSSI' AND certificat_genere = 1")->fetchColumn(),
    'en_cours' => $db->query("SELECT COUNT(*) FROM usss_formations_resultats WHERE resultat = 'EN_COURS'")->fetchColumn(),
];

$typeColors = [
    'THEORIQUE' => '#3b82f6',
    'PRATIQUE' => '#f59e0b',
    'CERTIFICATION' => '#22c55e',
    'RECYCLAGE' => '#8b5cf6'
];

if (isset($_GET['msg'])) { $message=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }
$formations=[];try{$formations=$db->query("SELECT f.*,a.nom_rp,a.prenom_rp FROM usss_formations f LEFT JOIN usss_agents a ON f.formateur_id=a.id ORDER BY f.created_at DESC")->fetchAll();}catch(Exception $e){}
$resultats=[];try{$resultats=$db->query("SELECT r.*,a.nom_rp,a.prenom_rp,a.badge_number,f.nom as form_nom FROM usss_formations_resultats r JOIN usss_agents a ON r.agent_id=a.id JOIN usss_formations f ON r.formation_id=f.id ORDER BY r.date_passage DESC LIMIT 20")->fetchAll();}catch(Exception $e){}
$agents=[];try{$agents=$db->query("SELECT id,nom_rp,prenom_rp,badge_number FROM usss_agents WHERE statut='EN_SERVICE' ORDER BY nom_rp")->fetchAll();}catch(Exception $e){}

include __DIR__ . '/includes/header.php';
?>
<?php if($message):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $message;?></div><?php endif;?>

<div class="grid2">
<div>
  <div class="card">
    <div class="sec-head"><div class="card-title" style="border:none;margin:0;padding:0"><i class="fas fa-graduation-cap"></i> Formations Disponibles</div>
      <?php if($isAdmin):?><button onclick="USSS.openModal('newFormModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Créer</button><?php endif;?>
    </div>
    <?php if(empty($formations)):?><div style="text-align:center;padding:2.5rem;color:var(--txt-m);font-size:.82rem">Aucune formation</div>
    <?php else: foreach($formations as $f):?>
    <div style="padding:.75rem .9rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;margin-bottom:.45rem">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.65rem">
        <div>
          <div style="font-weight:600;font-size:.85rem"><?php echo htmlspecialchars($f['nom']);?></div>
          <div style="font-size:.72rem;color:var(--txt-s);margin-top:.1rem"><?php echo htmlspecialchars($f['type']); echo $f['specialisation']?' · '.htmlspecialchars($f['specialisation']):'';?></div>
        </div>
        <div style="text-align:right;flex-shrink:0">
          <div class="mono" style="font-size:.75rem;color:var(--blue-xl)"><?php echo $f['duree_heures'];?>h</div>
          <?php if($f['note_minimale']):?><div style="font-size:.68rem;color:var(--txt-m)">Note min: <?php echo $f['note_minimale'];?>/20</div><?php endif;?>
        </div>
      </div>
      <?php if($f['nom_rp']):?><div style="font-size:.68rem;color:var(--txt-m);margin-top:.25rem"><i class="fas fa-chalkboard-teacher" style="margin-right:.25rem"></i><?php echo htmlspecialchars($f['nom_rp'].' '.$f['prenom_rp']);?></div><?php endif;?>
    </div>
    <?php endforeach; endif;?>
  </div>
</div>
<div>
  <div class="card">
    <div class="sec-head"><div class="card-title" style="border:none;margin:0;padding:0"><i class="fas fa-clipboard-check"></i> Résultats Récents</div>
      <button onclick="USSS.openModal('newResModal')" class="btn btn-ghost btn-sm"><i class="fas fa-plus"></i> Enregistrer</button>
    </div>
    <?php if(empty($resultats)):?><div style="text-align:center;padding:2.5rem;color:var(--txt-m);font-size:.82rem">Aucun résultat</div>
    <?php else: foreach($resultats as $r):$pass=($r['note']??0)>=($r['note_minimale']??10);?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem .15rem;border-bottom:1px solid var(--bdr)">
      <div style="width:32px;height:32px;border-radius:6px;background:<?php echo $pass?'rgba(21,128,61,.15)':'rgba(191,28,46,.12)';?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="fas fa-<?php echo $pass?'check':'times';?>" style="color:<?php echo $pass?'var(--green-l)':'var(--red-l)';?>;font-size:.75rem"></i>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:.83rem;font-weight:600"><?php echo htmlspecialchars($r['nom_rp'].' '.$r['prenom_rp']);?></div>
        <div style="font-size:.7rem;color:var(--txt-s)"><?php echo htmlspecialchars($r['form_nom']);?></div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div class="mono" style="font-size:.82rem;font-weight:600;color:<?php echo $pass?'var(--green-l)':'var(--red-l)';?>"><?php echo $r['note']??'—';?>/20</div>
        <div class="mono" style="font-size:.62rem;color:var(--txt-m)"><?php echo $r['date_passage']?date('d.m.Y',strtotime($r['date_passage'])):'—';?></div>
      </div>
    </div>
    <?php endforeach; endif;?>
  </div>
</div>
</div>

<!-- MODAL FORMATION -->
<div id="newFormModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-plus"></i> Créer une Formation</h2><button class="modal-cl" onclick="USSS.closeModal('newFormModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_formation">
  <div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Type *</label>
      <select name="type" class="form-select" required><option value="INITIALE">Initiale</option><option value="CONTINUE">Continue</option><option value="SPECIALISATION">Spécialisation</option><option value="CERTIFICATION">Certification</option></select>
    </div>
    <div class="form-group"><label class="form-label">Durée (h)</label><input type="number" name="duree_heures" class="form-input" min="1" value="8"></div>
    <div class="form-group"><label class="form-label">Note minimale</label><input type="number" name="note_minimale" class="form-input" min="0" max="20" step="0.5" value="10"></div>
    <div class="form-group"><label class="form-label">Formateur</label>
      <select name="formateur_id" class="form-select"><option value="">Aucun</option><?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>"><?php echo htmlspecialchars($a['nom_rp'].' '.$a['prenom_rp']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Validité (mois)</label><input type="number" name="validite_mois" class="form-input" min="0" value="12"></div>
    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" style="min-height:65px"></textarea></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('newFormModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>

<!-- MODAL RÉSULTAT -->
<div id="newResModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-clipboard-check"></i> Enregistrer un Résultat</h2><button class="modal-cl" onclick="USSS.closeModal('newResModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="enregistrer_resultat">
  <div class="form-grid">
    <div class="form-group"><label class="form-label">Agent *</label>
      <select name="agent_id" class="form-select" required><option value="">Sélectionner…</option><?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>"><?php echo htmlspecialchars($a['nom_rp'].' '.$a['prenom_rp'].' ('.$a['badge_number'].')');?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Formation *</label>
      <select name="formation_id" class="form-select" required><option value="">Sélectionner…</option><?php foreach($formations as $f):?><option value="<?php echo $f['id'];?>"><?php echo htmlspecialchars($f['nom']);?></option><?php endforeach;?></select>
    </div>
    <div class="form-group"><label class="form-label">Note /20</label><input type="number" name="note" class="form-input" min="0" max="20" step="0.5"></div>
    <div class="form-group"><label class="form-label">Date de passage</label><input type="date" name="date_passage" class="form-input" value="<?php echo date('Y-m-d');?>"></div>
  </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="USSS.closeModal('newResModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
</form></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>