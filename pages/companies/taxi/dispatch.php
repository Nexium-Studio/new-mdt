<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🚕 Taxi Los Santos';
$companyColor   = '#f59e0b';
$companyFaction = 'TAXI';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Dispatch';
$pageSubtitle = 'Central de répartition des courses';
$db  = Database::getInstance()->getConnection();
$uid = $_SESSION['user_id'];
$isChef = hasRole(['*','Administrateur','Développeur','Staff','Directeur','Responsable','Dispatcher']);

// ─── ACTIONS ─────────────────────────────────────────────────────────────────
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CRÉER COURSE
    if ($action === 'create_course') {
        try {
            $year = date('Y');
            $db->exec("INSERT IGNORE INTO taxi_sequences (type,annee,dernier) VALUES ('CRS',$year,0)");
            $db->exec("UPDATE taxi_sequences SET dernier=dernier+1 WHERE type='CRS' AND annee=$year");
            $seq = $db->query("SELECT dernier FROM taxi_sequences WHERE type='CRS' AND annee=$year")->fetchColumn();
            $ref = 'CRS-'.$year.'-'.str_pad($seq, 5, '0', STR_PAD_LEFT);

            // Tarif par défaut
            $tarifDefault = $db->query("SELECT id FROM taxi_tarifs WHERE defaut=1 LIMIT 1")->fetchColumn();

            $stmt = $db->prepare("
                INSERT INTO taxi_courses
                    (reference, client_nom, client_telephone, depart_adresse, arrivee_adresse,
                     date_demande, statut, urgente, nb_passagers, bagages, notes_internes, tarif_id, created_by)
                VALUES (?,?,?,?,?,NOW(),'en_attente',?,?,?,?,?,?)
            ");
            $stmt->execute([
                $ref,
                trim($_POST['client_nom']),
                trim($_POST['client_telephone'] ?? ''),
                trim($_POST['depart_adresse']),
                trim($_POST['arrivee_adresse']),
                isset($_POST['urgente']) ? 1 : 0,
                intval($_POST['nb_passagers'] ?? 1),
                isset($_POST['bagages']) ? 1 : 0,
                trim($_POST['notes_internes'] ?? ''),
                $tarifDefault ?: null,
                $uid,
            ]);
            $msg = "Course $ref créée."; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // ASSIGNER CHAUFFEUR
    if ($action === 'assign') {
        try {
            $courseId   = intval($_POST['course_id']);
            $chauffeurId= intval($_POST['chauffeur_id']);

            $db->prepare("
                UPDATE taxi_courses
                SET statut='assignee', chauffeur_id=?, updated_at=NOW()
                WHERE id=? AND statut='en_attente'
            ")->execute([$chauffeurId, $courseId]);

            // Mettre à jour statut chauffeur
            $db->prepare("UPDATE taxi_chauffeurs SET statut='en_course' WHERE id=?")->execute([$chauffeurId]);

            $msg = 'Course assignée.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // DÉMARRER COURSE
    if ($action === 'start_course') {
        try {
            $courseId = intval($_POST['course_id']);
            $db->prepare("
                UPDATE taxi_courses SET statut='en_cours', heure_prise_en_charge=NOW(), updated_at=NOW()
                WHERE id=? AND statut='assignee'
            ")->execute([$courseId]);
            $msg = 'Course démarrée.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // TERMINER COURSE
    if ($action === 'finish_course') {
        try {
            $courseId   = intval($_POST['course_id']);
            $distance   = floatval(str_replace(',','.',$_POST['distance_km'] ?? 0));
            $montant    = floatval(str_replace(',','.',$_POST['montant_total'] ?? 0));
            $pourboire  = floatval(str_replace(',','.',$_POST['pourboire'] ?? 0));
            $modePaie   = $_POST['mode_paiement'] ?? 'especes';
            $note       = !empty($_POST['note_client']) ? floatval($_POST['note_client']) : null;
            $commentaire= trim($_POST['commentaire_client'] ?? '');
            $notes      = trim($_POST['notes_internes_fin'] ?? '');

            // Récupérer la course
            $course = $db->prepare("SELECT * FROM taxi_courses WHERE id=?")->execute([$courseId]);
            $course = $db->query("SELECT * FROM taxi_courses WHERE id=$courseId")->fetch();
            if (!$course) throw new Exception('Course introuvable.');

            // Durée
            $duree = null;
            if ($course['heure_prise_en_charge']) {
                $duree = round((time() - strtotime($course['heure_prise_en_charge'])) / 60);
            }

            $db->prepare("
                UPDATE taxi_courses SET
                    statut='terminee',
                    heure_arrivee=NOW(),
                    duree_course=?,
                    distance_km=?,
                    montant_total=?,
                    pourboire=?,
                    mode_paiement=?,
                    payee=1,
                    note_client=?,
                    commentaire_client=?,
                    notes_internes=?,
                    updated_at=NOW()
                WHERE id=?
            ")->execute([$duree, $distance, $montant, $pourboire, $modePaie, $note, $commentaire, $notes, $courseId]);

            // Mettre à jour chauffeur
            if ($course['chauffeur_id']) {
                $db->prepare("
                    UPDATE taxi_chauffeurs SET
                        statut='disponible',
                        total_courses=total_courses+1,
                        total_revenus=total_revenus+?
                    WHERE id=?
                ")->execute([$montant + $pourboire, $course['chauffeur_id']]);
            }

            $msg = 'Course terminée.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // ANNULER COURSE
    if ($action === 'cancel_course') {
        try {
            $courseId = intval($_POST['course_id']);
            $course   = $db->query("SELECT chauffeur_id FROM taxi_courses WHERE id=$courseId")->fetch();
            $db->prepare("
                UPDATE taxi_courses SET statut='annulee', updated_at=NOW() WHERE id=?
            ")->execute([$courseId]);
            if ($course['chauffeur_id']) {
                $db->prepare("UPDATE taxi_chauffeurs SET statut='disponible' WHERE id=?")->execute([$course['chauffeur_id']]);
            }
            $msg = 'Course annulée.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
}

// Réponse JSON compteur nav
if (isset($_GET['_count'])) {
    $n = $db->query("SELECT COUNT(*) FROM taxi_courses WHERE statut='en_attente'")->fetchColumn();
    header('Content-Type: application/json'); echo json_encode(['attente'=>(int)$n]); exit;
}

// ─── DONNÉES ─────────────────────────────────────────────────────────────────
// Courses en attente
$coursesAttente = $db->query("
    SELECT c.*, cl.type as client_type,
           TIMESTAMPDIFF(MINUTE, c.date_demande, NOW()) as mins_attente
    FROM taxi_courses c
    LEFT JOIN taxi_clients cl ON c.client_id=cl.id
    WHERE c.statut='en_attente'
    ORDER BY c.urgente DESC, c.date_demande ASC
")->fetchAll();

// Courses assignées
$coursesAssignees = $db->query("
    SELECT c.*, tc.numero_badge, u.username as ch_nom
    FROM taxi_courses c
    JOIN taxi_chauffeurs tc ON c.chauffeur_id=tc.id
    JOIN users u ON tc.user_id=u.id
    WHERE c.statut='assignee'
    ORDER BY c.updated_at DESC
")->fetchAll();

// Courses en cours
$coursesEnCours = $db->query("
    SELECT c.*, tc.numero_badge, u.username as ch_nom,
           TIMESTAMPDIFF(MINUTE, c.heure_prise_en_charge, NOW()) as mins_en_cours
    FROM taxi_courses c
    JOIN taxi_chauffeurs tc ON c.chauffeur_id=tc.id
    JOIN users u ON tc.user_id=u.id
    WHERE c.statut='en_cours'
    ORDER BY c.heure_prise_en_charge ASC
")->fetchAll();

// Chauffeurs disponibles
$chauffeursDispos = $db->query("
    SELECT tc.*, u.username, u.faction_rank
    FROM taxi_chauffeurs tc
    JOIN users u ON tc.user_id=u.id
    WHERE tc.actif=1 AND tc.statut='disponible'
    ORDER BY tc.note_moyenne DESC
")->fetchAll();

// Chauffeurs en course
$chauffeursEnCourse = $db->query("
    SELECT tc.*, u.username
    FROM taxi_chauffeurs tc
    JOIN users u ON tc.user_id=u.id
    WHERE tc.actif=1 AND tc.statut='en_course'
")->fetchAll();

// Tarifs
$tarifs = $db->query("SELECT * FROM taxi_tarifs WHERE actif=1")->fetchAll();

// Pré-sélection course à assigner depuis dashboard
$preAssignId = intval($_GET['assign'] ?? 0);

include __DIR__ . '/includes/header.php';
?>

<style>
.dispatch-board {
    display:grid;
    grid-template-columns:1fr 1fr 1fr;
    gap:1.1rem;
    align-items:start;
}

.dispatch-col { }
.dispatch-col-hd {
    display:flex; align-items:center; justify-content:space-between;
    padding:.75rem 1rem; border-radius:10px 10px 0 0;
    margin-bottom:0;
}
.dc-attente  { background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(245,158,11,.06)); border:1px solid rgba(245,158,11,.2); border-bottom:none; }
.dc-assignee { background:linear-gradient(135deg,rgba(59,130,246,.1),rgba(59,130,246,.05)); border:1px solid rgba(59,130,246,.2); border-bottom:none; }
.dc-en_cours { background:linear-gradient(135deg,rgba(124,58,237,.1),rgba(124,58,237,.05)); border:1px solid rgba(124,58,237,.2); border-bottom:none; }

.dispatch-cards {
    border:1px solid rgba(245,158,11,.1);
    border-top:none;
    border-radius:0 0 10px 10px;
    overflow:hidden;
}
.dispatch-cards.blue   { border-color:rgba(59,130,246,.15); }
.dispatch-cards.purple { border-color:rgba(124,58,237,.15); }

.d-card {
    background:rgba(13,17,23,.8);
    border-bottom:1px solid rgba(245,158,11,.07);
    padding:1rem 1.1rem;
    transition:background .2s;
}
.d-card:last-child { border-bottom:none; }
.d-card:hover { background:rgba(13,17,23,.95); }
.d-card.urgente { border-left:3px solid #ef4444; background:rgba(239,68,68,.04); }

.d-card-top {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:.4rem;
}
.d-ref   { font-size:.7rem; color:var(--muted); font-family:monospace; }
.d-name  { font-size:.88rem; font-weight:700; color:var(--text); margin-bottom:.2rem; }
.d-route { font-size:.78rem; color:#94a3b8; margin-bottom:.45rem; }
.d-route i { color:var(--col); }
.d-meta  { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
.d-actions { display:flex; gap:.4rem; margin-top:.65rem; flex-wrap:wrap; }

.wait-chip {
    font-size:.72rem; font-weight:700; padding:.18rem .5rem;
    border-radius:6px; background:rgba(245,158,11,.12); color:var(--col-lt);
}
.wait-chip.long { background:rgba(239,68,68,.12); color:#f87171; }

/* Chauffeurs sidebar */
.drivers-sidebar {
    background:rgba(13,17,23,.7);
    border:1px solid var(--border);
    border-radius:14px; overflow:hidden;
    position:sticky; top:80px;
}
.drivers-sidebar-hd {
    padding:.85rem 1.1rem;
    border-bottom:1px solid var(--border);
    font-size:.82rem; font-weight:700; color:var(--text);
    display:flex; align-items:center; gap:.5rem;
}
.drivers-sidebar-hd i { color:var(--col); }

.driver-dispo-row {
    padding:.75rem 1.1rem;
    border-bottom:1px solid rgba(245,158,11,.06);
    display:flex; align-items:center; gap:.75rem;
    cursor:pointer; transition:background .2s;
}
.driver-dispo-row:hover { background:rgba(245,158,11,.05); }
.driver-dispo-row:last-child { border-bottom:none; }
.ddr-av {
    width:34px; height:34px; border-radius:50%;
    background:linear-gradient(135deg,var(--col),var(--col-dk));
    color:#000; font-weight:800; font-size:.88rem;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.ddr-info { flex:1; min-width:0; }
.ddr-name { font-size:.83rem; font-weight:700; color:var(--text); }
.ddr-note { font-size:.7rem; color:var(--muted); }
.ddr-badge { flex-shrink:0; }

/* Calculateur tarif */
.tarif-calc {
    background:rgba(245,158,11,.05);
    border:1px solid rgba(245,158,11,.2);
    border-radius:10px; padding:1rem;
    margin-top:.75rem;
}
.tarif-calc-row {
    display:flex; justify-content:space-between; align-items:center;
    font-size:.82rem; padding:.25rem 0;
    border-bottom:1px solid rgba(245,158,11,.07);
}
.tarif-calc-row:last-child { border-bottom:none; }
.tarif-calc-total {
    font-size:1.1rem; font-weight:800; color:var(--col-lt);
    padding-top:.5rem;
}
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- Barre d'action rapide -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.65rem;">
    <div style="display:flex;gap:.65rem;align-items:center;flex-wrap:wrap;">
        <div style="font-size:.85rem;color:var(--muted);">
            <span style="color:var(--col-lt);font-weight:700;"><?php echo count($coursesAttente); ?></span> en attente ·
            <span style="color:#60a5fa;font-weight:700;"><?php echo count($coursesAssignees); ?></span> assignées ·
            <span style="color:#a78bfa;font-weight:700;"><?php echo count($coursesEnCours); ?></span> en cours ·
            <span style="color:#4ade80;font-weight:700;"><?php echo count($chauffeursDispos); ?></span> chauffeurs disponibles
        </div>
    </div>
    <button onclick="Taxi.openModal('modal-new-course')" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouvelle Course
    </button>
</div>

<div style="display:grid;grid-template-columns:1fr 220px;gap:1.25rem;align-items:start;">

    <!-- Board Dispatch -->
    <div class="dispatch-board">

        <!-- COLONNE : EN ATTENTE -->
        <div class="dispatch-col">
            <div class="dispatch-col-hd dc-attente">
                <span style="font-size:.8rem;font-weight:700;color:var(--col-lt);display:flex;align-items:center;gap:.4rem;">
                    <i class="fas fa-hourglass-half"></i> En Attente
                </span>
                <span class="badge b-attente"><?php echo count($coursesAttente); ?></span>
            </div>
            <div class="dispatch-cards">
                <?php if (empty($coursesAttente)): ?>
                <div style="padding:1.5rem;text-align:center;color:var(--muted);font-size:.82rem;">
                    <i class="fas fa-check" style="color:#4ade80;"></i> Aucune course
                </div>
                <?php endif; ?>
                <?php foreach ($coursesAttente as $c): ?>
                <div class="d-card <?php echo $c['urgente']?'urgente':''; ?>" id="card-<?php echo $c['id']; ?>">
                    <div class="d-card-top">
                        <span class="d-ref"><?php echo htmlspecialchars($c['reference']); ?></span>
                        <span class="wait-chip <?php echo $c['mins_attente']>15?'long':''; ?>">
                            <?php echo $c['mins_attente']; ?>min
                        </span>
                    </div>
                    <div class="d-name">
                        <?php echo htmlspecialchars($c['client_nom']); ?>
                        <?php if ($c['urgente']): ?><span class="badge b-urgente" style="margin-left:.3rem;font-size:.65rem;">URGENTE</span><?php endif; ?>
                    </div>
                    <div class="d-route">
                        <i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($c['depart_adresse']); ?>
                        <br><i class="fas fa-flag-checkered" style="color:#4ade80;"></i> <?php echo htmlspecialchars($c['arrivee_adresse']); ?>
                    </div>
                    <div class="d-meta">
                        <?php if ($c['client_telephone']): ?>
                        <span style="font-size:.75rem;color:var(--muted);"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($c['client_telephone']); ?></span>
                        <?php endif; ?>
                        <span style="font-size:.75rem;color:var(--muted);"><i class="fas fa-users"></i> <?php echo $c['nb_passagers']; ?></span>
                        <?php if ($c['bagages']): ?><span class="badge" style="background:rgba(245,158,11,.1);color:var(--col-lt);font-size:.65rem;">BAGAGES</span><?php endif; ?>
                    </div>
                    <div class="d-actions">
                        <button onclick="openAssign(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['reference'])); ?>', '<?php echo htmlspecialchars(addslashes($c['client_nom'])); ?>')"
                                class="btn btn-primary btn-xs"><i class="fas fa-paper-plane"></i> Assigner</button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="cancel_course">
                            <input type="hidden" name="course_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-xs" data-confirm="Annuler cette course ?">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- COLONNE : ASSIGNÉES -->
        <div class="dispatch-col">
            <div class="dispatch-col-hd dc-assignee">
                <span style="font-size:.8rem;font-weight:700;color:#60a5fa;display:flex;align-items:center;gap:.4rem;">
                    <i class="fas fa-user-check"></i> Assignées
                </span>
                <span class="badge b-assignee"><?php echo count($coursesAssignees); ?></span>
            </div>
            <div class="dispatch-cards blue">
                <?php if (empty($coursesAssignees)): ?>
                <div style="padding:1.5rem;text-align:center;color:var(--muted);font-size:.82rem;">Aucune course assignée</div>
                <?php endif; ?>
                <?php foreach ($coursesAssignees as $c): ?>
                <div class="d-card <?php echo $c['urgente']?'urgente':''; ?>">
                    <div class="d-card-top">
                        <span class="d-ref"><?php echo htmlspecialchars($c['reference']); ?></span>
                    </div>
                    <div class="d-name"><?php echo htmlspecialchars($c['client_nom']); ?></div>
                    <div class="d-route">
                        <i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($c['depart_adresse']); ?>
                        <br><i class="fas fa-flag-checkered" style="color:#4ade80;"></i> <?php echo htmlspecialchars($c['arrivee_adresse']); ?>
                    </div>
                    <div class="d-meta">
                        <span style="font-size:.75rem;color:#60a5fa;font-weight:600;"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($c['ch_nom']); ?> #<?php echo htmlspecialchars($c['numero_badge']); ?></span>
                    </div>
                    <div class="d-actions">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="start_course">
                            <input type="hidden" name="course_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" class="btn btn-info btn-xs"><i class="fas fa-play"></i> Démarrer</button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="cancel_course">
                            <input type="hidden" name="course_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-xs" data-confirm="Annuler ?">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- COLONNE : EN COURS -->
        <div class="dispatch-col">
            <div class="dispatch-col-hd dc-en_cours">
                <span style="font-size:.8rem;font-weight:700;color:#a78bfa;display:flex;align-items:center;gap:.4rem;">
                    <i class="fas fa-car-side"></i> En Cours
                </span>
                <span class="badge b-en_cours"><?php echo count($coursesEnCours); ?></span>
            </div>
            <div class="dispatch-cards purple">
                <?php if (empty($coursesEnCours)): ?>
                <div style="padding:1.5rem;text-align:center;color:var(--muted);font-size:.82rem;">Aucune course en cours</div>
                <?php endif; ?>
                <?php foreach ($coursesEnCours as $c): ?>
                <div class="d-card <?php echo $c['urgente']?'urgente':''; ?>">
                    <div class="d-card-top">
                        <span class="d-ref"><?php echo htmlspecialchars($c['reference']); ?></span>
                        <span style="font-size:.72rem;color:#a78bfa;font-weight:700;">
                            <i class="fas fa-clock"></i> <?php echo $c['mins_en_cours']; ?>min
                        </span>
                    </div>
                    <div class="d-name"><?php echo htmlspecialchars($c['client_nom']); ?></div>
                    <div class="d-route">
                        <i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($c['depart_adresse']); ?>
                        <br><i class="fas fa-flag-checkered" style="color:#4ade80;"></i> <?php echo htmlspecialchars($c['arrivee_adresse']); ?>
                    </div>
                    <div class="d-meta">
                        <span style="font-size:.75rem;color:#a78bfa;font-weight:600;"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($c['ch_nom']); ?> #<?php echo htmlspecialchars($c['numero_badge']); ?></span>
                    </div>
                    <div class="d-actions">
                        <button onclick="openFinish(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['reference'])); ?>')"
                                class="btn btn-success btn-xs"><i class="fas fa-flag-checkered"></i> Terminer</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar chauffeurs disponibles -->
    <div class="drivers-sidebar">
        <div class="drivers-sidebar-hd"><i class="fas fa-car"></i> Disponibles (<?php echo count($chauffeursDispos); ?>)</div>
        <?php if (empty($chauffeursDispos)): ?>
        <div style="padding:1.5rem;text-align:center;color:var(--muted);font-size:.8rem;">Aucun disponible</div>
        <?php endif; ?>
        <?php foreach ($chauffeursDispos as $ch): ?>
        <div class="driver-dispo-row" data-id="<?php echo $ch['id']; ?>" data-name="<?php echo htmlspecialchars($ch['username']); ?>" onclick="selectDriver(this)">
            <div class="ddr-av"><?php echo strtoupper(substr($ch['username'],0,1)); ?></div>
            <div class="ddr-info">
                <div class="ddr-name"><?php echo htmlspecialchars($ch['username']); ?></div>
                <div class="ddr-note">
                    #<?php echo htmlspecialchars($ch['numero_badge']); ?>
                    <?php if ($ch['note_moyenne']): ?>· <span class="stars" style="font-size:.65rem;"><?php echo str_repeat('★',round($ch['note_moyenne'])); ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div style="height:1px;background:var(--border);margin:.3rem 0;"></div>
        <div style="padding:.5rem .8rem;font-size:.72rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:1px;">En Course</div>
        <?php foreach ($chauffeursEnCourse as $ch): ?>
        <div class="driver-dispo-row">
            <div class="ddr-av" style="background:linear-gradient(135deg,#a78bfa,#7c3aed);"><?php echo strtoupper(substr($ch['username'],0,1)); ?></div>
            <div class="ddr-info">
                <div class="ddr-name"><?php echo htmlspecialchars($ch['username']); ?></div>
                <div class="ddr-note">#<?php echo htmlspecialchars($ch['numero_badge']); ?></div>
            </div>
            <span class="badge b-en_course" style="font-size:.62rem;">En course</span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ═══ MODAL NOUVELLE COURSE ════════════════════════════════════ -->
<div id="modal-new-course" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Nouvelle Course</h2>
            <button class="modal-close" onclick="Taxi.closeModal('modal-new-course')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_course">
            <div class="modal-body">
                <div class="form-grid" style="margin-bottom:1.1rem;">
                    <div class="form-group">
                        <label class="form-label">Nom du client *</label>
                        <input type="text" name="client_nom" class="form-input" placeholder="Prénom Nom" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Téléphone</label>
                        <input type="text" name="client_telephone" class="form-input" placeholder="555-XXXX">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:1.1rem;">
                    <label class="form-label">Adresse de départ *</label>
                    <input type="text" name="depart_adresse" class="form-input" placeholder="Adresse, rue..." required>
                </div>
                <div class="form-group" style="margin-bottom:1.1rem;">
                    <label class="form-label">Adresse d'arrivée *</label>
                    <input type="text" name="arrivee_adresse" class="form-input" placeholder="Destination..." required>
                </div>
                <div class="form-grid3" style="margin-bottom:1.1rem;">
                    <div class="form-group">
                        <label class="form-label">Passagers</label>
                        <input type="number" name="nb_passagers" class="form-input" value="1" min="1" max="8">
                    </div>
                    <div class="form-group" style="justify-content:flex-end;">
                        <label class="form-label" style="margin-bottom:.5rem;">Bagages</label>
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem;color:#94a3b8;">
                            <input type="checkbox" name="bagages" style="accent-color:var(--col);width:16px;height:16px;"> Oui
                        </label>
                    </div>
                    <div class="form-group" style="justify-content:flex-end;">
                        <label class="form-label" style="margin-bottom:.5rem;">Urgente</label>
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem;color:#f87171;">
                            <input type="checkbox" name="urgente" style="accent-color:#ef4444;width:16px;height:16px;"> Urgente
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes internes</label>
                    <textarea name="notes_internes" class="form-textarea" placeholder="Instructions particulières..." style="min-height:65px;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Taxi.closeModal('modal-new-course')" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Créer la course</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ MODAL ASSIGNER ═══════════════════════════════════════════ -->
<div id="modal-assign" class="modal-overlay hidden">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2><i class="fas fa-user-check"></i> Assigner un Chauffeur</h2>
            <button class="modal-close" onclick="Taxi.closeModal('modal-assign')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="course_id" id="assign-course-id">
            <div class="modal-body">
                <div style="margin-bottom:1.1rem;padding:.75rem 1rem;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.2);border-radius:9px;">
                    <div style="font-size:.72rem;color:var(--muted);margin-bottom:.2rem;">Course</div>
                    <div style="font-weight:700;color:var(--text);" id="assign-course-info"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Chauffeur *</label>
                    <select name="chauffeur_id" class="form-select" required id="assign-chauffeur-select">
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($chauffeursDispos as $ch): ?>
                        <option value="<?php echo $ch['id']; ?>">
                            <?php echo htmlspecialchars($ch['username']); ?> #<?php echo htmlspecialchars($ch['numero_badge']); ?>
                            <?php if ($ch['note_moyenne']): ?>(★ <?php echo number_format($ch['note_moyenne'],1); ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($chauffeursDispos)): ?>
                    <div class="alert alert-warning" style="margin-top:.5rem;">Aucun chauffeur disponible actuellement.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Taxi.closeModal('modal-assign')" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary" <?php echo empty($chauffeursDispos)?'disabled':''; ?>>
                    <i class="fas fa-check"></i> Assigner
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ MODAL TERMINER COURSE ════════════════════════════════════ -->
<div id="modal-finish" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-flag-checkered"></i> Terminer la Course</h2>
            <button class="modal-close" onclick="Taxi.closeModal('modal-finish')">×</button>
        </div>
        <form method="POST" id="finish-form">
            <input type="hidden" name="action" value="finish_course">
            <input type="hidden" name="course_id" id="finish-course-id">
            <div class="modal-body">
                <div style="margin-bottom:1rem;padding:.65rem 1rem;background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.2);border-radius:9px;">
                    <span style="font-size:.72rem;color:var(--muted);">Course : </span>
                    <span style="font-weight:700;color:#a78bfa;" id="finish-course-ref"></span>
                </div>

                <div class="form-grid" style="margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label">Distance (km) *</label>
                        <input type="number" name="distance_km" id="calc-km" class="form-input" placeholder="0.0" step="0.1" min="0" oninput="calcTarif()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mode de Paiement</label>
                        <select name="mode_paiement" class="form-select">
                            <option value="especes">💵 Espèces</option>
                            <option value="carte">💳 Carte</option>
                            <option value="virement">🏦 Virement</option>
                            <option value="compte">📒 Sur Compte</option>
                        </select>
                    </div>
                </div>

                <!-- Calculateur -->
                <div class="tarif-calc">
                    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--col);margin-bottom:.5rem;">
                        <i class="fas fa-calculator"></i> Calcul Tarif
                    </div>
                    <div class="tarif-calc-row">
                        <span>Prise en charge</span>
                        <span id="calc-pec">$0.00</span>
                    </div>
                    <div class="tarif-calc-row">
                        <span>Course (<span id="calc-km-disp">0</span> km)</span>
                        <span id="calc-course">$0.00</span>
                    </div>
                    <div class="tarif-calc-row">
                        <span>Pourboire</span>
                        <input type="number" name="pourboire" id="calc-pourboire" placeholder="0.00" step="0.01" min="0"
                               style="background:transparent;border:none;border-bottom:1px solid rgba(245,158,11,.3);color:var(--col-lt);font-size:.82rem;width:70px;text-align:right;outline:none;" oninput="calcTarif()">
                    </div>
                    <div class="tarif-calc-row tarif-calc-total">
                        <span>TOTAL</span>
                        <span id="calc-total">$0.00</span>
                    </div>
                    <input type="hidden" name="montant_total" id="hidden-total">
                </div>

                <div class="form-grid" style="margin-top:1rem;margin-bottom:.75rem;">
                    <div class="form-group">
                        <label class="form-label">Note Client (1-5)</label>
                        <select name="note_client" class="form-select">
                            <option value="">— Sans note —</option>
                            <option value="5">★★★★★ Excellent</option>
                            <option value="4">★★★★☆ Bien</option>
                            <option value="3">★★★☆☆ Moyen</option>
                            <option value="2">★★☆☆☆ Passable</option>
                            <option value="1">★☆☆☆☆ Mauvais</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Commentaire Client</label>
                        <input type="text" name="commentaire_client" class="form-input" placeholder="Retour du client...">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes Internes</label>
                    <textarea name="notes_internes_fin" class="form-textarea" placeholder="Observations chauffeur / dispatch..." style="min-height:55px;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Taxi.closeModal('modal-finish')" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Valider & Terminer</button>
            </div>
        </form>
    </div>
</div>

<script>
// Tarif par défaut du serveur
const TARIF_PEC = <?php echo $tarifs[0]['tarif_prise_en_charge'] ?? 5; ?>;
const TARIF_KM  = <?php echo $tarifs[0]['tarif_par_km'] ?? 2.5; ?>;

function calcTarif() {
    const km = parseFloat(document.getElementById('calc-km').value) || 0;
    const tip= parseFloat(document.getElementById('calc-pourboire').value) || 0;
    const course = km * TARIF_KM;
    const total  = TARIF_PEC + course + tip;

    document.getElementById('calc-pec').textContent    = '$'+TARIF_PEC.toFixed(2);
    document.getElementById('calc-km-disp').textContent= km.toFixed(1);
    document.getElementById('calc-course').textContent = '$'+course.toFixed(2);
    document.getElementById('calc-total').textContent  = '$'+total.toFixed(2);
    document.getElementById('hidden-total').value      = total.toFixed(2);
}
calcTarif();

function openAssign(courseId, ref, client) {
    document.getElementById('assign-course-id').value = courseId;
    document.getElementById('assign-course-info').textContent = ref + ' — ' + client;
    Taxi.openModal('modal-assign');
}

function openFinish(courseId, ref) {
    document.getElementById('finish-course-id').value = courseId;
    document.getElementById('finish-course-ref').textContent = ref;
    Taxi.openModal('modal-finish');
}

function selectDriver(row) {
    document.querySelectorAll('.driver-dispo-row').forEach(r=>r.style.background='');
    row.style.background = 'rgba(245,158,11,.1)';
    const sel = document.getElementById('assign-chauffeur-select');
    if (sel) sel.value = row.dataset.id;
}

<?php if ($preAssignId): ?>
// Ouverture automatique depuis dashboard
const preCard = document.getElementById('card-<?php echo $preAssignId; ?>');
if (preCard) { preCard.scrollIntoView({behavior:'smooth'}); }
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>