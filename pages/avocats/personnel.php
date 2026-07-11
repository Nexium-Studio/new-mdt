<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Personnel';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    // Recruter : on cherche un compte users qui correspond au profile sélectionné
    if ($action === 'recruit') {
        try {
            $profileId = intval($_POST['profile_id']);
            $rank      = trim($_POST['rank']);

            // Récupérer le profil
            $p = $db->prepare("SELECT * FROM profiles WHERE id=?");
            $p->execute([$profileId]); $profile = $p->fetch();
            if (!$profile) throw new Exception("Profil introuvable.");

            // Trouver le compte MDT correspondant (par username ou par IBAN ou par ID direct fourni)
            $manualUserId = intval($_POST['manual_user_id'] ?? 0);
            $user = null;

            if ($manualUserId > 0) {
                $s = $db->prepare("SELECT id, username, faction FROM users WHERE id=?");
                $s->execute([$manualUserId]); $user = $s->fetch();
            } else {
                // Tentative de correspondance automatique via nom/prénom
                $fn = trim($profile['firstname']); $ln = trim($profile['lastname']);
                $s = $db->prepare("
                    SELECT id, username, faction FROM users
                    WHERE LOWER(username) LIKE LOWER(?) OR LOWER(username) LIKE LOWER(?) OR LOWER(username) LIKE LOWER(?)
                    LIMIT 1
                ");
                $s->execute(["%$fn%$ln%", "%$ln%$fn%", "%$fn%"]);
                $user = $s->fetch();
            }

            if (!$user) throw new Exception("Aucun compte MDT trouvé pour ce profil. Renseignez l'ID MDT manuellement.");
            if (!in_array($user['faction'], ['NONE', null, ''])) throw new Exception("Ce compte appartient déjà à la faction : {$user['faction']}");

            $db->prepare("UPDATE users SET faction='AVOCATS', faction_rank=? WHERE id=?")->execute([$rank, $user['id']]);
            $msg = htmlspecialchars($user['username'])." recruté(e) — Grade : ".htmlspecialchars($rank);
            $msgType = 'ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }

    if ($action === 'update_rank') {
        $db->prepare("UPDATE users SET faction_rank=? WHERE id=? AND faction='AVOCATS'")->execute([trim($_POST['rank']), intval($_POST['user_id'])]);
        $msg='Grade mis à jour.'; $msgType='ok';
    }

    if ($action === 'fire') {
        $s = $db->prepare("SELECT username FROM users WHERE id=? AND faction='AVOCATS'");
        $s->execute([intval($_POST['user_id'])]); $fired = $s->fetch();
        $db->prepare("UPDATE users SET faction='NONE', faction_rank=NULL WHERE id=? AND faction='AVOCATS'")->execute([intval($_POST['user_id'])]);
        $msg = ($fired ? htmlspecialchars($fired['username']) : 'Employé(e)').' licencié(e).'; $msgType='ok';
    }
}

$GRADES = [
    'Avocat associé principal',
    'Avocat associé',
    'Avocat senior',
    'Avocat junior',
    'Stagiaire avocat',
    'Secrétaire juridique',
];

// Tous les profils
$search = trim($_GET['q'] ?? '');
$qProfiles = "SELECT * FROM profiles WHERE 1=1";
$pParams = [];
if ($search) {
    $qProfiles .= " AND (firstname LIKE ? OR lastname LIKE ? OR phone LIKE ? OR job LIKE ?)";
    $pParams = ["%$search%","%$search%","%$search%","%$search%"];
}
$qProfiles .= " ORDER BY lastname, firstname";
$stmtP = $db->prepare($qProfiles); $stmtP->execute($pParams);
$allProfiles = $stmtP->fetchAll();

// Personnel du cabinet
$staff = $db->query("
    SELECT u.id, u.username, u.faction_rank, u.created_at,
           (SELECT COUNT(*) FROM avocat_dossiers d WHERE d.avocat_id=u.id) AS nb_dossiers,
           (SELECT COUNT(*) FROM avocat_dossiers d WHERE d.avocat_id=u.id AND d.statut IN ('ouvert','en_cours','tribunal')) AS nb_actifs,
           (SELECT COUNT(*) FROM avocat_dossiers d WHERE d.avocat_id=u.id AND d.statut='gagne') AS nb_gagnes,
           (SELECT COUNT(*) FROM avocat_dossiers d WHERE d.avocat_id=u.id AND d.statut='perdu') AS nb_perdus,
           (SELECT COALESCE(SUM(ai.amount),0) FROM avocat_invoices ai WHERE ai.avocat_id=u.id AND ai.statut='paid') AS revenus,
           (SELECT COALESCE(SUM(ai.amount),0) FROM avocat_invoices ai WHERE ai.avocat_id=u.id AND ai.statut='pending') AS impayees
    FROM users u WHERE u.faction='AVOCATS'
    ORDER BY FIELD(u.faction_rank,'Avocat associé principal','Avocat associé','Avocat senior','Avocat junior','Stagiaire avocat','Secrétaire juridique'), u.username
")->fetchAll();

include __DIR__ . '/includes/header.php';

if (!$__canRecruit) {
    echo '<div class="alert alert-inf"><i class="fas fa-lock"></i> Accès réservé aux Avocats Associés et supérieurs.</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
?>
<style>
.staff-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(295px,1fr));gap:1rem;margin-bottom:1.5rem}
.staff-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.2rem;transition:border-color .2s}
.staff-card:hover{border-color:var(--bdr-h)}

/* Table profiles */
.profiles-wrap{max-height:420px;overflow-y:auto;border-radius:0 0 10px 10px}
.profiles-wrap::-webkit-scrollbar{width:4px}
.profiles-wrap::-webkit-scrollbar-thumb{background:rgba(217,119,6,.3);border-radius:2px}
.profile-row{display:flex;align-items:center;gap:.85rem;padding:.75rem 1rem;border-bottom:1px solid rgba(255,255,255,.04);cursor:pointer;transition:background .15s}
.profile-row:hover{background:rgba(217,119,6,.05)}
.profile-row:last-child{border-bottom:none}
.profile-row.selected{background:rgba(217,119,6,.1);border-left:3px solid var(--gold)}
.pr-av{width:36px;height:36px;border-radius:9px;background:rgba(217,119,6,.15);color:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0}
.pr-name{font-weight:700;font-size:.845rem;color:#f5edd6}
.pr-sub{font-size:.7rem;color:var(--txt-m);margin-top:.1rem}
.pr-badges{display:flex;gap:.3rem;margin-top:.25rem;flex-wrap:wrap}
.pr-select-btn{margin-left:auto;flex-shrink:0}

/* Formulaire recrutement (affiché quand sélection) */
.recruit-form{background:rgba(217,119,6,.06);border:1px solid rgba(217,119,6,.25);border-radius:10px;padding:1.1rem 1.25rem;margin-top:1rem;display:none}
.recruit-form.visible{display:block}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<!-- ── PERSONNEL ACTUEL ───────────────────────────────────── -->
<div class="card" style="margin-bottom:1.4rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.75rem">
        <div class="card-title" style="margin:0"><i class="fas fa-users"></i> Personnel du cabinet (<?php echo count($staff);?> membre(s))</div>
        <?php if(!empty($staff)):?>
        <div style="display:flex;gap:.65rem;flex-wrap:wrap;font-size:.72rem;color:var(--txt-m)">
            <span><i class="fas fa-folder" style="color:var(--gold)"></i> <?php echo array_sum(array_column($staff,'nb_dossiers'));?> dossiers total</span>
            <span><i class="fas fa-trophy" style="color:#4ade80"></i> <?php echo array_sum(array_column($staff,'nb_gagnes'));?> gagnés</span>
            <span><i class="fas fa-coins" style="color:var(--gold)"></i> $<?php echo number_format(array_sum(array_column($staff,'revenus')),0,'.',',');?> CA cumulé</span>
        </div>
        <?php endif;?>
    </div>

    <?php if(empty($staff)):?>
    <div style="text-align:center;padding:3rem;color:var(--txt-m)">
        <i class="fas fa-user-tie" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
        Aucun membre — recrutez depuis le panneau ci-dessous
    </div>
    <?php else:?>
    <div class="tbl-wrap" style="margin-bottom:0">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Membre</th>
                    <th>Grade</th>
                    <th>Dossiers</th>
                    <th>Actifs</th>
                    <th>W/L</th>
                    <th>CA généré</th>
                    <th>Impayées</th>
                    <th>Depuis</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($staff as $m):
                $grade = $m['faction_rank']??'—';
                $gc=['Avocat associé principal'=>'#fbbf24','Avocat associé'=>'#fcd34d','Avocat senior'=>'#93c5fd','Avocat junior'=>'#c4b5fd','Stagiaire avocat'=>'#94a3b8','Secrétaire juridique'=>'#6b7280'][$grade]??'#94a3b8';
                $wr = ($m['nb_gagnes']+$m['nb_perdus'])>0 ? round($m['nb_gagnes']/($m['nb_gagnes']+$m['nb_perdus'])*100).'%' : '—';
            ?>
            <tr>
                <!-- Nom -->
                <td>
                    <div style="display:flex;align-items:center;gap:.7rem">
                        <div style="width:34px;height:34px;border-radius:9px;background:rgba(217,119,6,.15);color:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.82rem;flex-shrink:0">
                            <?php echo strtoupper(substr($m['username'],0,1));?>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:.845rem;color:#f5edd6"><?php echo htmlspecialchars($m['username']);?></div>
                        </div>
                    </div>
                </td>
                <!-- Grade -->
                <td>
                    <span class="badge" style="background:<?php echo $gc;?>18;color:<?php echo $gc;?>;border:1px solid <?php echo $gc;?>33;font-size:.65rem">
                        <?php echo htmlspecialchars($grade);?>
                    </span>
                </td>
                <!-- Stats -->
                <td class="mono" style="font-size:.82rem;color:var(--txt-s)"><?php echo $m['nb_dossiers'];?></td>
                <td>
                    <?php if($m['nb_actifs']>0):?>
                    <span class="badge bg-blue" style="font-size:.65rem"><?php echo $m['nb_actifs'];?> actif(s)</span>
                    <?php else:?><span style="color:var(--txt-m);font-size:.78rem">—</span><?php endif;?>
                </td>
                <td style="font-size:.78rem">
                    <span style="color:#4ade80"><?php echo $m['nb_gagnes'];?>W</span>
                    <span style="color:var(--txt-m)"> / </span>
                    <span style="color:#f87171"><?php echo $m['nb_perdus'];?>L</span>
                    <?php if($wr!=='—'):?><span style="color:var(--txt-m);font-size:.7rem"> (<?php echo $wr;?>)</span><?php endif;?>
                </td>
                <td class="mono txt-green" style="font-weight:700;font-size:.82rem">$<?php echo number_format($m['revenus'],0,'.',',');?></td>
                <td class="mono" style="font-size:.78rem;color:<?php echo $m['impayees']>0?'#f87171':'var(--txt-m)';?>">
                    <?php echo $m['impayees']>0?'$'.number_format($m['impayees'],0,'.',','):'—';?>
                </td>
                <td style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y',strtotime($m['created_at']));?></td>
                <!-- Actions -->
                <td>
                    <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                        <!-- Changer grade -->
                        <button onclick="openGradeModal(<?php echo $m['id'];?>, '<?php echo addslashes($m['username']);?>', '<?php echo addslashes($grade);?>')"
                                class="btn btn-secondary btn-sm" title="Changer le grade">
                            <i class="fas fa-medal"></i>
                        </button>
                        <!-- Générer document -->
                        <button onclick="openGenModal(<?php echo $m['id'];?>, '<?php echo addslashes($m['username']);?>', '<?php echo addslashes($grade);?>')"
                                class="btn btn-ghost btn-sm" title="Générer un document">
                            <i class="fas fa-wand-magic-sparkles"></i>
                        </button>
                        <!-- Licencier -->
                        <?php if($__isManaging):?>
                        <form method="POST" style="margin:0" onsubmit="return confirm('Licencier <?php echo addslashes($m['username']);?> du cabinet ?')">
                            <input type="hidden" name="action" value="fire">
                            <input type="hidden" name="user_id" value="<?php echo $m['id'];?>">
                            <button type="submit" class="btn btn-danger btn-sm" title="Licencier">
                                <i class="fas fa-user-times"></i>
                            </button>
                        </form>
                        <?php endif;?>
                    </div>
                </td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>
    <?php endif;?>
</div>

<!-- ── MODAL CHANGER GRADE ────────────────────────────────── -->
<div id="gradeModal" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd"><h2><i class="fas fa-medal"></i> Changer le grade</h2><button class="modal-cl" onclick="Cabinet.closeModal('gradeModal')">×</button></div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="update_rank">
                <input type="hidden" name="user_id" id="gm_uid">
                <p style="margin-bottom:1rem;font-size:.875rem;color:var(--txt-s)">
                    Membre : <strong id="gm_name" style="color:#f5edd6"></strong>
                </p>
                <div class="form-group">
                    <label class="form-label">Nouveau grade *</label>
                    <select name="rank" id="gm_rank" class="form-select" required>
                        <?php foreach($GRADES as $g):?><option value="<?php echo $g;?>"><?php echo $g;?></option><?php endforeach;?>
                    </select>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="Cabinet.closeModal('gradeModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ── MODAL GÉNÉRER DOCUMENT ────────────────────────────── -->
<div id="genModal" class="modal-ov hidden">
    <div class="modal" style="max-width:760px">
        <div class="modal-hd">
            <h2><i class="fas fa-wand-magic-sparkles"></i> Générer un document pour <span id="gen_name" style="color:var(--gold-l)"></span></h2>
            <button class="modal-cl" onclick="Cabinet.closeModal('genModal')">×</button>
        </div>
        <div class="modal-bd">
            <!-- Choix du type de document -->
            <div id="docTypeGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:.65rem;margin-bottom:1.25rem"></div>

            <!-- Champs supplémentaires -->
            <div class="form-group" id="gen_extra_motif" style="display:none;margin-bottom:.85rem">
                <label class="form-label">Motif / Précisions</label>
                <textarea id="gen_motif" class="form-textarea" style="min-height:70px" placeholder="Détails à inclure dans le document…"></textarea>
            </div>
            <div class="form-group" id="gen_extra_newgrade" style="display:none;margin-bottom:.85rem">
                <label class="form-label">Nouveau grade (pour promotion)</label>
                <select id="gen_newgrade" class="form-select">
<?php foreach($GRADES as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
<?php endforeach; ?>
                </select>
            </div>

            <button onclick="generateStaffDoc()" class="btn btn-primary" style="width:100%;justify-content:center;margin-bottom:1rem">
                <i class="fas fa-wand-magic-sparkles"></i> Générer le document
            </button>

            <div style="position:relative">
                <div id="gen_loading" style="display:none;position:absolute;inset:0;background:rgba(6,8,15,.85);border-radius:10px;align-items:center;justify-content:center;color:var(--gold-l);flex-direction:column;gap:.5rem">
                    <i class="fas fa-spinner" style="font-size:1.5rem;animation:spin 1s linear infinite"></i>
                    <span style="font-size:.82rem">Génération en cours…</span>
                </div>
                <div id="gen_output" style="background:var(--bg-ov);border:1px solid var(--bdr);border-radius:10px;padding:1.25rem;font-size:.845rem;line-height:1.8;color:var(--txt-s);white-space:pre-wrap;min-height:120px;font-family:'IBM Plex Sans',serif">
                    <span style="color:var(--txt-m);font-style:italic">Sélectionnez un type de document puis cliquez sur Générer…</span>
                </div>
            </div>
        </div>
        <div class="modal-ft">
            <button type="button" class="btn btn-ghost" onclick="copyGen()"><i class="fas fa-copy"></i> Copier</button>
            <button type="button" class="btn btn-secondary" onclick="saveGenDoc()"><i class="fas fa-save"></i> Sauvegarder</button>
            <button type="button" class="btn btn-ghost" onclick="Cabinet.closeModal('genModal')">Fermer</button>
        </div>
    </div>
</div>

<!-- Formulaire caché pour sauvegarder dans documents.php -->
<form method="POST" action="documents.php" id="saveGenForm" style="display:none">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="type" value="contrat">
    <input type="hidden" name="titre" id="saveGenTitre">
    <input type="hidden" name="contenu" id="saveGenContenu">
    <input type="hidden" name="client_id" value="">
    <input type="hidden" name="dossier_id" value="">
</form>

<!-- ── RECRUTER ───────────────────────────────────────────── -->
<div class="card">
    <div class="card-title"><i class="fas fa-user-plus"></i> Recruter — Sélectionner dans le recensement LSPD</div>

    <!-- Barre de recherche -->
    <form method="GET" style="display:flex;gap:.65rem;margin-bottom:.85rem" id="searchForm">
        <div style="position:relative;flex:1">
            <i class="fas fa-search" style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--txt-m);font-size:.8rem"></i>
            <input type="text" name="q" id="searchInput" class="form-input" style="padding-left:2.4rem"
                   placeholder="Filtrer par nom, prénom, métier, téléphone…"
                   value="<?php echo htmlspecialchars($search);?>"
                   oninput="filterProfiles(this.value)">
        </div>
        <?php if($search):?><a href="personnel.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Effacer</a><?php endif;?>
    </form>

    <!-- Liste tous les profils -->
    <div style="font-size:.7rem;color:var(--txt-m);margin-bottom:.5rem;padding:0 .25rem">
        <span id="profileCount"><?php echo count($allProfiles);?></span> citoyen(s) recensé(s)
        <?php if($search):?> pour "<?php echo htmlspecialchars($search);?>"<?php endif;?>
    </div>

    <div class="profiles-wrap" id="profilesList">
        <?php if(empty($allProfiles)):?>
        <div style="text-align:center;padding:2rem;color:var(--txt-m)">Aucun citoyen trouvé</div>
        <?php else:?>
        <?php foreach($allProfiles as $pr):
            $init = strtoupper(substr($pr['firstname'],0,1));
        ?>
        <div class="profile-row" id="prow-<?php echo $pr['id'];?>"
             onclick="selectProfile(<?php echo $pr['id'];?>, '<?php echo addslashes($pr['firstname']); ?>', '<?php echo addslashes($pr['lastname']); ?>', '<?php echo addslashes($pr['job']??''); ?>', '<?php echo addslashes($pr['age']??''); ?>')">
            <div class="pr-av"><?php echo $init;?></div>
            <div style="flex:1;min-width:0">
                <div class="pr-name"><?php echo htmlspecialchars($pr['firstname'].' '.$pr['lastname']);?></div>
                <div class="pr-sub">
                    <?php if($pr['age']):?><?php echo $pr['age'];?> ans<?php endif;?>
                    <?php if($pr['nationality']):?> · <?php echo htmlspecialchars($pr['nationality']);?><?php endif;?>
                    <?php if($pr['job']):?> · <em><?php echo htmlspecialchars($pr['job']);?></em><?php endif;?>
                    <?php if($pr['phone']):?> · <?php echo htmlspecialchars($pr['phone']);?><?php endif;?>
                </div>
                <div class="pr-badges">
                    <?php if($pr['license_car']):?><span class="badge bg-green" style="font-size:.6rem"><i class="fas fa-car"></i></span><?php endif;?>
                    <?php if($pr['license_ppa']):?><span class="badge bg-blue" style="font-size:.6rem">PPA</span><?php endif;?>
                    <?php if($pr['license_boat']):?><span class="badge bg-blue" style="font-size:.6rem"><i class="fas fa-sailboat"></i></span><?php endif;?>
                    <?php if($pr['license_plane']):?><span class="badge bg-blue" style="font-size:.6rem"><i class="fas fa-plane"></i></span><?php endif;?>
                    <?php if($pr['blood_type']):?><span class="badge bg-red" style="font-size:.6rem"><?php echo $pr['blood_type'];?></span><?php endif;?>
                </div>
            </div>
            <div class="pr-select-btn">
                <span class="btn btn-secondary btn-sm" style="pointer-events:none">
                    <i class="fas fa-plus"></i> Sélectionner
                </span>
            </div>
        </div>
        <?php endforeach;?>
        <?php endif;?>
    </div>

    <!-- Formulaire de recrutement (apparaît après sélection) -->
    <div class="recruit-form" id="recruitForm">
        <div style="display:flex;align-items:center;gap:.85rem;margin-bottom:1rem">
            <div style="width:42px;height:42px;border-radius:10px;background:rgba(217,119,6,.2);color:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.95rem;flex-shrink:0" id="rf_avatar">?</div>
            <div>
                <div style="font-weight:700;font-size:.95rem;color:#f5edd6" id="rf_name">—</div>
                <div style="font-size:.75rem;color:var(--txt-m)" id="rf_info">—</div>
            </div>
            <button type="button" onclick="clearSelection()" class="btn btn-ghost btn-sm" style="margin-left:auto">
                <i class="fas fa-times"></i> Changer
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="recruit">
            <input type="hidden" name="profile_id" id="rf_profile_id">

            <div class="form-grid" style="margin-bottom:1rem">
                <div class="form-group">
                    <label class="form-label">Grade *</label>
                    <select name="rank" class="form-select" required>
                        <?php foreach($GRADES as $g):?><option value="<?php echo $g;?>"><?php echo $g;?></option><?php endforeach;?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">
                        ID MDT (si pas trouvé automatiquement)
                        <span style="font-size:.65rem;color:var(--txt-m)"> — optionnel</span>
                    </label>
                    <input type="number" name="manual_user_id" class="form-input" placeholder="Laissez vide pour détection auto">
                </div>
            </div>

            <div style="background:rgba(217,119,6,.06);border-radius:8px;padding:.65rem .9rem;font-size:.75rem;color:var(--txt-m);margin-bottom:.85rem">
                <i class="fas fa-info-circle" style="color:var(--gold);margin-right:.4rem"></i>
                Le système va tenter de trouver automatiquement le compte MDT correspondant.
                Si le recrutement échoue, renseignez l'ID MDT manuellement.
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-gavel"></i> Recruter au cabinet
            </button>
        </form>
    </div>
</div>

<script>
/* ── TYPES DE DOCUMENTS (définis en JS pur, sans PHP) ── */
const DOC_TYPES = [
    { id: 'embauche',      icon: 'fa-handshake',             label: "Lettre d'embauche",    desc: 'Accueil au cabinet' },
    { id: 'contrat',       icon: 'fa-file-signature',        label: 'Contrat de travail',   desc: 'Contrat CDI/CDD' },
    { id: 'promotion',     icon: 'fa-award',                 label: 'Lettre de promotion',  desc: 'Changement de grade' },
    { id: 'avertissement', icon: 'fa-triangle-exclamation',  label: 'Avertissement',        desc: 'Mise en demeure' },
    { id: 'attestation',   icon: 'fa-certificate',           label: "Attestation d'emploi", desc: "Preuve d'emploi" },
    { id: 'licenciement',  icon: 'fa-user-times',            label: 'Licenciement',         desc: 'Fin de contrat' },
];

/* Construire la grille de choix dynamiquement */
document.addEventListener('DOMContentLoaded', function() {
    const grid = document.getElementById('docTypeGrid');
    if (!grid) return;
    DOC_TYPES.forEach(function(dt) {
        const lbl = document.createElement('label');
        lbl.id = 'dtype-' + dt.id;
        lbl.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:.45rem;padding:.9rem .75rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:10px;cursor:pointer;transition:all .18s;text-align:center';
        lbl.innerHTML =
            '<input type="radio" name="doc_type_sel" value="' + dt.id + '" style="display:none">' +
            '<i class="fas ' + dt.icon + '" style="font-size:1.2rem;color:var(--txt-s);transition:color .18s"></i>' +
            '<span style="font-size:.78rem;font-weight:700;color:var(--txt-s);transition:color .18s">' + dt.label + '</span>' +
            '<span style="font-size:.65rem;color:var(--txt-m)">' + dt.desc + '</span>';
        lbl.addEventListener('click', function() { selectDocType(lbl, dt.id); });
        grid.appendChild(lbl);
    });
});

/* ── FILTRE PROFILES ── */
function filterProfiles(q) {
    q = q.toLowerCase().trim();
    const rows = document.querySelectorAll('.profile-row');
    let visible = 0;
    rows.forEach(function(row) {
        const match = !q || row.textContent.toLowerCase().includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const counter = document.getElementById('profileCount');
    if (counter) counter.textContent = visible;
}

/* ── SÉLECTION PROFIL ── */
let selectedRow = null;
function selectProfile(id, firstname, lastname, job, age) {
    if (selectedRow) selectedRow.classList.remove('selected');
    selectedRow = document.getElementById('prow-' + id);
    if (selectedRow) selectedRow.classList.add('selected');
    document.getElementById('rf_profile_id').value = id;
    document.getElementById('rf_avatar').textContent = firstname.charAt(0).toUpperCase();
    document.getElementById('rf_name').textContent   = firstname + ' ' + lastname;
    document.getElementById('rf_info').textContent   = [age ? age + ' ans' : '', job].filter(Boolean).join(' · ');
    const form = document.getElementById('recruitForm');
    form.classList.add('visible');
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function clearSelection() {
    if (selectedRow) { selectedRow.classList.remove('selected'); selectedRow = null; }
    document.getElementById('recruitForm').classList.remove('visible');
    document.getElementById('rf_profile_id').value = '';
}

/* ── MODAL GRADE ── */
function openGradeModal(uid, name, currentGrade) {
    document.getElementById('gm_uid').value = uid;
    document.getElementById('gm_name').textContent = name;
    const sel = document.getElementById('gm_rank');
    for (let i = 0; i < sel.options.length; i++) {
        sel.options[i].selected = (sel.options[i].value === currentGrade);
    }
    Cabinet.openModal('gradeModal');
}

/* ── MODAL GÉNÉRATEUR ── */
let genUserId = null, genUserName = '', genUserGrade = '';
let selectedDocType = null;

function openGenModal(uid, name, grade) {
    genUserId = uid;
    genUserName = name;
    genUserGrade = grade;
    document.getElementById('gen_name').textContent = name;
    // Reset
    document.querySelectorAll('[id^="dtype-"]').forEach(function(el) {
        el.style.background = '';
        el.style.borderColor = '';
        el.querySelector('i').style.color = 'var(--txt-s)';
        const spans = el.querySelectorAll('span:not([style*="display"])');
        if (spans[0]) spans[0].style.color = 'var(--txt-s)';
        const radio = el.querySelector('input[type=radio]');
        if (radio) radio.checked = false;
    });
    selectedDocType = null;
    document.getElementById('gen_output').innerHTML = '<span style="color:var(--txt-m);font-style:italic">Sélectionnez un type de document puis cliquez sur Générer…</span>';
    document.getElementById('gen_extra_motif').style.display = 'none';
    document.getElementById('gen_extra_newgrade').style.display = 'none';
    document.getElementById('gen_motif').value = '';
    Cabinet.openModal('genModal');
}

function selectDocType(label, type) {
    document.querySelectorAll('[id^="dtype-"]').forEach(function(el) {
        el.style.background = '';
        el.style.borderColor = '';
        const ico = el.querySelector('i');
        const spn = el.querySelector('span[style*="font-weight"]');
        if (ico) ico.style.color = 'var(--txt-s)';
        if (spn) spn.style.color = 'var(--txt-s)';
    });
    label.style.background   = 'rgba(217,119,6,.12)';
    label.style.borderColor  = 'rgba(217,119,6,.5)';
    const ico = label.querySelector('i');
    const spn = label.querySelector('span[style*="font-weight"]');
    if (ico) ico.style.color = 'var(--gold)';
    if (spn) spn.style.color = 'var(--gold-l)';
    const radio = label.querySelector('input[type=radio]');
    if (radio) radio.checked = true;
    selectedDocType = type;

    document.getElementById('gen_extra_motif').style.display =
        ['avertissement','licenciement','contrat'].includes(type) ? 'block' : 'none';
    document.getElementById('gen_extra_newgrade').style.display =
        (type === 'promotion') ? 'block' : 'none';
}

async function generateStaffDoc() {
    if (!selectedDocType) { alert('Sélectionnez un type de document.'); return; }

    const out      = document.getElementById('gen_output');
    const loading  = document.getElementById('gen_loading');
    const motif    = document.getElementById('gen_motif').value || '';
    const newGrade = document.getElementById('gen_newgrade').value || '';
    const today    = new Date().toLocaleDateString('fr-FR');
    const cabinet  = "Cabinet d'Avocats de Los Santos";

    const buildPrompt = {
        embauche: function() {
            return "Génère une lettre d'embauche officielle du " + cabinet + " pour :\n" +
                   "Nom : " + genUserName + "\nGrade : " + genUserGrade + "\nDate : " + today + "\n" +
                   "La lettre doit être chaleureuse et professionnelle, mentionner le grade, les responsabilités au cabinet de Los Santos, et souhaiter la bienvenue.";
        },
        contrat: function() {
            return "Génère un contrat de travail complet pour le " + cabinet + " :\n" +
                   "Employé : " + genUserName + "\nPoste : " + genUserGrade + "\nDate : " + today +
                   (motif ? "\nConditions : " + motif : "") + "\n" +
                   "Inclure : parties, objet, poste et missions, durée, horaires, rémunération, confidentialité, loyauté, signatures.";
        },
        promotion: function() {
            return "Génère une lettre de promotion officielle du " + cabinet + " :\n" +
                   "Employé promu : " + genUserName + "\nAncien grade : " + genUserGrade +
                   "\nNouveau grade : " + (newGrade || 'grade supérieur') + "\nDate : " + today + "\n" +
                   "Féliciter, souligner les mérites, annoncer la promotion et les nouvelles responsabilités.";
        },
        avertissement: function() {
            return "Génère une lettre d'avertissement officielle du " + cabinet + " :\n" +
                   "Destinataire : " + genUserName + "\nGrade : " + genUserGrade + "\nDate : " + today + "\n" +
                   "Motif : " + (motif || "manquements aux obligations professionnelles") + "\n" +
                   "Rappeler les faits, les obligations contractuelles non respectées, avertir des conséquences.";
        },
        attestation: function() {
            return "Génère une attestation d'emploi officielle du " + cabinet + " :\n" +
                   "Employé : " + genUserName + "\nPoste : " + genUserGrade + "\nDate : " + today + "\n" +
                   "Certifier l'emploi, préciser le poste. Signé par le Directeur Associé Principal. Format officiel avec cachet.";
        },
        licenciement: function() {
            return "Génère une lettre de licenciement officielle du " + cabinet + " :\n" +
                   "Destinataire : " + genUserName + "\nGrade : " + genUserGrade + "\nDate : " + today + "\n" +
                   "Motif : " + (motif || "restructuration du cabinet") + "\n" +
                   "Formes légales, notification officielle, date d'effet, obligations post-contractuelles.";
        }
    };

    if (!buildPrompt[selectedDocType]) return;

    out.textContent = '';
    loading.style.display = 'flex';

    try {
        const res = await fetch('api_claude.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: 'claude-sonnet-4-20250514',
                max_tokens: 2048,
                system: "Tu es un assistant juridique pour le Cabinet d'Avocats de Los Santos (FiveM RP). Tu génères des documents RH professionnels en français. Réponds UNIQUEMENT avec le document, sans commentaire. Format officiel avec en-tête, date, corps et signature.",
                messages: [{ role: 'user', content: buildPrompt[selectedDocType]() }]
            })
        });
        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch(parseErr) {
            out.textContent = "Erreur proxy (réponse non-JSON) : " + rawText.substring(0, 400);
            return;
        }
        if (data.error) {
            out.textContent = "Erreur API : " + data.error.message;
        } else if (data.content && data.content[0] && data.content[0].type === 'text') {
            out.textContent = data.content[0].text;
        } else {
            out.textContent = "Réponse inattendue : " + JSON.stringify(data).substring(0, 300);
        }
    } catch(e) {
        out.textContent = "Erreur réseau : " + e.message;
    } finally {
        loading.style.display = 'none';
    }
}

function copyGen() {
    const txt = document.getElementById('gen_output').textContent.trim();
    if (!txt || txt.includes('Sélectionnez')) { alert('Rien à copier.'); return; }
    navigator.clipboard.writeText(txt).then(function() { alert('Copié !'); });
}

function saveGenDoc() {
    const content = document.getElementById('gen_output').textContent.trim();
    if (!content || content.includes('Sélectionnez')) { alert('Générez un document d\'abord.'); return; }
    const labels = {
        embauche: "Lettre d'embauche", contrat: 'Contrat de travail',
        promotion: 'Lettre de promotion', avertissement: 'Avertissement',
        attestation: "Attestation d'emploi", licenciement: 'Licenciement'
    };
    const titre = (labels[selectedDocType] || 'Document') + ' - ' + genUserName;
    document.getElementById('saveGenTitre').value = titre;
    document.getElementById('saveGenContenu').value = content;
    document.getElementById('saveGenForm').submit();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>