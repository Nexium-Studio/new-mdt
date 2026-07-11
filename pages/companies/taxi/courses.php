<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🚕 Taxi Los Santos';
$companyColor   = '#f59e0b';
$companyFaction = 'TAXI';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Courses';
$pageSubtitle = 'Historique & Gestion';
$db  = Database::getInstance()->getConnection();
$uid = $_SESSION['user_id'];
$isChef = hasRole(['*','Administrateur','Développeur','Staff','Directeur','Responsable']);

if (isset($_GET['_count'])) {
    $n = $db->query("SELECT COUNT(*) FROM taxi_courses WHERE statut='en_attente'")->fetchColumn();
    header('Content-Type:application/json'); echo json_encode(['attente'=>(int)$n]); exit;
}

// ─── ACTIONS ─────────────────────────────────────────────────────────────────
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete' && $isChef) {
        try {
            $db->prepare("DELETE FROM taxi_courses WHERE id=?")->execute([intval($_POST['course_id'])]);
            $msg = 'Course supprimée.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'add_incident') {
        try {
            $db->prepare("
                INSERT INTO taxi_incidents (course_id, type, description, gravite, rapporte_by)
                VALUES (?,?,?,?,?)
            ")->execute([
                intval($_POST['course_id']),
                $_POST['type'],
                trim($_POST['description']),
                $_POST['gravite'],
                $uid,
            ]);
            $msg = 'Incident enregistré.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'update_paiement') {
        try {
            $db->prepare("UPDATE taxi_courses SET payee=?, mode_paiement=?, updated_at=NOW() WHERE id=?")
               ->execute([intval($_POST['payee']), $_POST['mode_paiement'], intval($_POST['course_id'])]);
            $msg = 'Paiement mis à jour.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
}

// ─── FILTRES & PAGINATION ─────────────────────────────────────────────────────
$page       = max(1, intval($_GET['page'] ?? 1));
$perPage    = 20;
$offset     = ($page - 1) * $perPage;
$fStat      = $_GET['statut'] ?? '';
$fPaie      = $_GET['paiement'] ?? '';
$fUrgent    = $_GET['urgente'] ?? '';
$fDate      = $_GET['date'] ?? '';
$fChauffeur = $_GET['chauffeur'] ?? '';
$search     = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($fStat)      { $where[] = 'c.statut=?';              $params[] = $fStat; }
if ($fPaie)      { $where[] = 'c.mode_paiement=?';       $params[] = $fPaie; }
if ($fUrgent)    { $where[] = 'c.urgente=1'; }
if ($fDate)      { $where[] = 'DATE(c.date_demande)=?';  $params[] = $fDate; }
if ($fChauffeur) { $where[] = 'c.chauffeur_id=?';        $params[] = intval($fChauffeur); }
if ($search)     {
    $where[] = '(c.reference LIKE ? OR c.client_nom LIKE ? OR c.depart_adresse LIKE ? OR c.arrivee_adresse LIKE ?)';
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM taxi_courses c WHERE $whereStr");
$total->execute($params); $total = $total->fetchColumn();
$totalPages = ceil($total / $perPage);

$courses = $db->prepare("
    SELECT c.*,
           tc.numero_badge, u.username as chauffeur_nom,
           (SELECT COUNT(*) FROM taxi_incidents i WHERE i.course_id=c.id) as nb_incidents
    FROM taxi_courses c
    LEFT JOIN taxi_chauffeurs tc ON c.chauffeur_id=tc.id
    LEFT JOIN users u ON tc.user_id=u.id
    WHERE $whereStr
    ORDER BY c.date_demande DESC
    LIMIT $perPage OFFSET $offset
");
$courses->execute($params); $courses = $courses->fetchAll();

// Stats filtrées
$statsF = $db->prepare("
    SELECT COUNT(*) as nb, SUM(montant_total) as ca, AVG(note_client) as note
    FROM taxi_courses c WHERE $whereStr AND c.statut='terminee'
");
$statsF->execute($params); $statsF = $statsF->fetch();

// Chauffeurs pour filtre
$chauffeursList = $db->query("
    SELECT tc.id, u.username, tc.numero_badge FROM taxi_chauffeurs tc
    JOIN users u ON tc.user_id=u.id WHERE tc.actif=1 ORDER BY u.username
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
.course-row { transition:background .15s; }
.course-row:hover td { background:rgba(245,158,11,.04) !important; }

.course-route {
    font-size:.78rem; color:#94a3b8;
    max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}

.payee-chip {
    display:inline-flex; align-items:center; gap:.25rem;
    font-size:.7rem; font-weight:700; padding:.18rem .5rem; border-radius:6px;
}
.payee-yes { background:rgba(34,197,94,.1); color:#4ade80; }
.payee-no  { background:rgba(239,68,68,.1); color:#f87171; }

.stat-pill {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.35rem .75rem; border-radius:20px;
    font-size:.8rem; font-weight:600;
    background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.18); color:var(--col-lt);
}
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- Stats rapides -->
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem;">
    <div class="stat-pill"><i class="fas fa-route"></i> <?php echo number_format($total); ?> courses</div>
    <div class="stat-pill" style="background:rgba(34,197,94,.08);border-color:rgba(34,197,94,.2);color:#4ade80;">
        <i class="fas fa-dollar-sign"></i> $<?php echo number_format($statsF['ca'] ?? 0, 2); ?> CA
    </div>
    <div class="stat-pill" style="background:rgba(59,130,246,.08);border-color:rgba(59,130,246,.2);color:#60a5fa;">
        <i class="fas fa-check-circle"></i> <?php echo $statsF['nb'] ?? 0; ?> terminées
    </div>
    <?php if ($statsF['note']): ?>
    <div class="stat-pill" style="background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.2);color:var(--col-lt);">
        <i class="fas fa-star"></i> <?php echo number_format($statsF['note'],1); ?>/5
    </div>
    <?php endif; ?>
</div>

<!-- Filtres -->
<div class="card" style="padding:1rem 1.2rem;margin-bottom:1.1rem;">
    <form method="GET">
        <div class="filters-bar">
            <div class="search-wrap" style="flex:1;min-width:240px;">
                <i class="fas fa-search"></i>
                <input type="text" name="q" placeholder="Réf, client, adresse..." value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <select name="statut" class="form-select" style="width:auto;">
                <option value="">Tous statuts</option>
                <?php foreach (['en_attente','assignee','en_cours','terminee','annulee'] as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo $fStat===$s?'selected':''; ?>><?php echo ucfirst(str_replace('_',' ',$s)); ?></option>
                <?php endforeach; ?>
            </select>

            <select name="paiement" class="form-select" style="width:auto;">
                <option value="">Tous paiements</option>
                <?php foreach (['especes','carte','virement','compte'] as $p): ?>
                <option value="<?php echo $p; ?>" <?php echo $fPaie===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option>
                <?php endforeach; ?>
            </select>

            <select name="chauffeur" class="form-select" style="width:auto;">
                <option value="">Tous chauffeurs</option>
                <?php foreach ($chauffeursList as $ch): ?>
                <option value="<?php echo $ch['id']; ?>" <?php echo $fChauffeur===$ch['id']?'selected':''; ?>>
                    <?php echo htmlspecialchars($ch['username']); ?> #<?php echo htmlspecialchars($ch['numero_badge']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <input type="date" name="date" class="form-input" style="width:140px;" value="<?php echo htmlspecialchars($fDate); ?>">

            <label style="display:flex;align-items:center;gap:.35rem;font-size:.8rem;color:#f87171;cursor:pointer;white-space:nowrap;">
                <input type="checkbox" name="urgente" value="1" <?php echo $fUrgent?'checked':''; ?> style="accent-color:#ef4444;"> Urgentes seulement
            </label>

            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-filter"></i> Filtrer</button>
            <a href="courses.php" class="btn btn-sm" style="color:#f87171;border:1px solid rgba(239,68,68,.28);background:rgba(239,68,68,.08);">
                <i class="fas fa-times"></i>
            </a>
        </div>
    </form>
</div>

<!-- Table courses -->
<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid rgba(245,158,11,.1);display:flex;align-items:center;justify-content:space-between;">
        <div class="card-title" style="margin:0;"><i class="fas fa-list"></i> Courses (<?php echo number_format($total); ?>)</div>
        <div style="font-size:.78rem;color:var(--muted);">Page <?php echo $page; ?>/<?php echo max(1,$totalPages); ?></div>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Réf.</th>
                <th>Client</th>
                <th>Chauffeur</th>
                <th>Trajet</th>
                <th>Date</th>
                <th>Durée</th>
                <th>Dist.</th>
                <th>Montant</th>
                <th>Paiement</th>
                <th>Statut</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($courses)): ?>
            <tr><td colspan="11" style="text-align:center;padding:2.5rem;color:var(--muted);">Aucune course trouvée</td></tr>
            <?php endif; ?>
            <?php foreach ($courses as $c): ?>
            <tr class="course-row" <?php echo $c['urgente']?'style="background:rgba(239,68,68,.03);"':''; ?>>
                <td>
                    <span style="font-family:monospace;font-size:.75rem;color:var(--muted);"><?php echo htmlspecialchars($c['reference']); ?></span>
                    <?php if ($c['urgente']): ?><span class="badge b-urgente" style="font-size:.6rem;display:block;margin-top:.2rem;">URGENTE</span><?php endif; ?>
                    <?php if ($c['nb_incidents']): ?><span class="badge" style="background:rgba(239,68,68,.1);color:#f87171;font-size:.6rem;display:block;margin-top:.2rem;"><i class="fas fa-exclamation-triangle"></i> <?php echo $c['nb_incidents']; ?> incident(s)</span><?php endif; ?>
                </td>
                <td>
                    <div style="font-weight:600;color:var(--text);font-size:.85rem;"><?php echo htmlspecialchars($c['client_nom']); ?></div>
                    <?php if ($c['client_telephone']): ?><div style="font-size:.72rem;color:var(--muted);"><?php echo htmlspecialchars($c['client_telephone']); ?></div><?php endif; ?>
                </td>
                <td>
                    <?php if ($c['chauffeur_nom']): ?>
                    <div style="font-size:.83rem;color:var(--col-lt);font-weight:600;"><?php echo htmlspecialchars($c['chauffeur_nom']); ?></div>
                    <div style="font-size:.72rem;color:var(--muted);">#<?php echo htmlspecialchars($c['numero_badge']); ?></div>
                    <?php else: ?><span style="color:var(--muted);font-size:.8rem;">Non assigné</span><?php endif; ?>
                </td>
                <td>
                    <div class="course-route" title="<?php echo htmlspecialchars($c['depart_adresse']); ?> → <?php echo htmlspecialchars($c['arrivee_adresse']); ?>">
                        <i class="fas fa-map-pin" style="color:var(--col);font-size:.65rem;"></i> <?php echo htmlspecialchars($c['depart_adresse']); ?>
                        <br><i class="fas fa-flag-checkered" style="color:#4ade80;font-size:.65rem;"></i> <?php echo htmlspecialchars($c['arrivee_adresse']); ?>
                    </div>
                    <?php if ($c['nb_passagers'] > 1): ?><span style="font-size:.7rem;color:var(--muted);"><i class="fas fa-users"></i> <?php echo $c['nb_passagers']; ?>p</span><?php endif; ?>
                </td>
                <td style="font-size:.78rem;color:#94a3b8;white-space:nowrap;">
                    <?php echo date('d/m/Y', strtotime($c['date_demande'])); ?><br>
                    <span style="color:var(--muted);"><?php echo date('H:i', strtotime($c['date_demande'])); ?></span>
                </td>
                <td style="font-size:.82rem;"><?php echo $c['duree_course'] ? $c['duree_course'].'min' : '—'; ?></td>
                <td style="font-size:.82rem;"><?php echo $c['distance_km'] ? $c['distance_km'].'km' : '—'; ?></td>
                <td>
                    <?php if ($c['montant_total']): ?>
                    <div style="font-weight:700;color:var(--col-lt);">$<?php echo number_format($c['montant_total'],2); ?></div>
                    <?php if ($c['pourboire']): ?><div style="font-size:.7rem;color:#4ade80;">+$<?php echo number_format($c['pourboire'],2); ?> tip</div><?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                    <?php if ($c['statut']==='terminee'): ?>
                    <span class="payee-chip <?php echo $c['payee']?'payee-yes':'payee-no'; ?>">
                        <?php echo $c['payee']?'✓ Payée':'✗ Impayée'; ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($c['mode_paiement']): ?>
                    <span class="badge b-<?php echo $c['mode_paiement']; ?>"><?php echo ucfirst($c['mode_paiement']); ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="badge b-<?php echo $c['statut']; ?>"><?php echo ucfirst(str_replace('_',' ',$c['statut'])); ?></span></td>
                <td>
                    <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
                        <!-- Voir détail -->
                        <button onclick="Taxi.openModal('modal-detail-<?php echo $c['id']; ?>')" class="btn btn-secondary btn-xs" title="Détail">
                            <i class="fas fa-eye"></i>
                        </button>
                        <!-- Incident -->
                        <button onclick="Taxi.openModal('modal-incident-<?php echo $c['id']; ?>')" class="btn btn-warning btn-xs" title="Signaler incident">
                            <i class="fas fa-exclamation-triangle"></i>
                        </button>
                        <?php if ($isChef): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="course_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-xs" data-confirm="Supprimer définitivement ?" title="Supprimer">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>

            <!-- ─ MODAL DÉTAIL ─ -->
            <div id="modal-detail-<?php echo $c['id']; ?>" class="modal-overlay hidden">
                <div class="modal">
                    <div class="modal-header">
                        <h2><i class="fas fa-route"></i> <?php echo htmlspecialchars($c['reference']); ?></h2>
                        <button class="modal-close" onclick="Taxi.closeModal('modal-detail-<?php echo $c['id']; ?>')">×</button>
                    </div>
                    <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
                        <div>
                            <div style="margin-bottom:1rem;">
                                <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:.35rem;">Client</div>
                                <div style="font-weight:700;font-size:1rem;"><?php echo htmlspecialchars($c['client_nom']); ?></div>
                                <?php if ($c['client_telephone']): ?><div style="color:var(--muted);font-size:.82rem;"><?php echo htmlspecialchars($c['client_telephone']); ?></div><?php endif; ?>
                            </div>
                            <div style="margin-bottom:1rem;">
                                <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:.35rem;">Trajet</div>
                                <div style="font-size:.85rem;line-height:1.6;">
                                    <span style="color:var(--col);">📍 Départ :</span> <?php echo htmlspecialchars($c['depart_adresse']); ?><br>
                                    <span style="color:#4ade80;">🏁 Arrivée :</span> <?php echo htmlspecialchars($c['arrivee_adresse']); ?>
                                </div>
                            </div>
                            <?php if ($c['chauffeur_nom']): ?>
                            <div style="margin-bottom:1rem;">
                                <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:.35rem;">Chauffeur</div>
                                <div style="font-weight:700;color:var(--col-lt);"><?php echo htmlspecialchars($c['chauffeur_nom']); ?></div>
                                <div style="font-size:.78rem;color:var(--muted);">Badge #<?php echo htmlspecialchars($c['numero_badge']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div style="background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.18);border-radius:10px;padding:1rem;margin-bottom:1rem;">
                                <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:.5rem;">Tarification</div>
                                <div style="display:flex;justify-content:space-between;margin-bottom:.3rem;font-size:.83rem;">
                                    <span>Montant course</span><span>$<?php echo number_format($c['montant_course']??0,2); ?></span>
                                </div>
                                <?php if ($c['pourboire']): ?>
                                <div style="display:flex;justify-content:space-between;margin-bottom:.3rem;font-size:.83rem;">
                                    <span>Pourboire</span><span style="color:#4ade80;">+$<?php echo number_format($c['pourboire'],2); ?></span>
                                </div>
                                <?php endif; ?>
                                <div style="display:flex;justify-content:space-between;font-size:1rem;font-weight:800;color:var(--col-lt);padding-top:.4rem;border-top:1px solid rgba(245,158,11,.15);margin-top:.3rem;">
                                    <span>TOTAL</span><span>$<?php echo number_format($c['montant_total']??0,2); ?></span>
                                </div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.65rem;font-size:.8rem;color:#94a3b8;">
                                <div><span style="color:var(--muted);display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:.15rem;">Distance</span><?php echo $c['distance_km']?$c['distance_km'].'km':'—'; ?></div>
                                <div><span style="color:var(--muted);display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:.15rem;">Durée</span><?php echo $c['duree_course']?$c['duree_course'].'min':'—'; ?></div>
                                <div><span style="color:var(--muted);display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:.15rem;">Passagers</span><?php echo $c['nb_passagers']; ?></div>
                                <div><span style="color:var(--muted);display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:.15rem;">Note Client</span>
                                    <?php if ($c['note_client']): ?><span class="stars"><?php echo str_repeat('★',intval($c['note_client'])); ?></span> <?php echo $c['note_client']; ?>/5<?php else: ?>—<?php endif; ?>
                                </div>
                            </div>
                            <?php if ($c['commentaire_client']): ?>
                            <div style="margin-top:.75rem;padding:.6rem .75rem;background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.15);border-radius:8px;font-size:.8rem;color:#a78bfa;font-style:italic;">
                                "<?php echo htmlspecialchars($c['commentaire_client']); ?>"
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($c['notes_internes']): ?>
                    <div style="padding:0 1.65rem 1rem;">
                        <div style="background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.15);border-radius:8px;padding:.75rem;font-size:.8rem;color:#94a3b8;">
                            <i class="fas fa-sticky-note" style="color:var(--col);margin-right:.3rem;"></i> <?php echo htmlspecialchars($c['notes_internes']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- Paiement -->
                    <?php if ($c['statut']==='terminee'): ?>
                    <div style="padding:0 1.65rem 1.5rem;">
                        <form method="POST" style="display:flex;gap:.65rem;align-items:flex-end;flex-wrap:wrap;">
                            <input type="hidden" name="action" value="update_paiement">
                            <input type="hidden" name="course_id" value="<?php echo $c['id']; ?>">
                            <div class="form-group">
                                <label class="form-label">Statut paiement</label>
                                <select name="payee" class="form-select" style="width:160px;">
                                    <option value="1" <?php echo $c['payee']?'selected':''; ?>>✓ Payée</option>
                                    <option value="0" <?php echo !$c['payee']?'selected':''; ?>>✗ Impayée</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Mode</label>
                                <select name="mode_paiement" class="form-select" style="width:140px;">
                                    <?php foreach (['especes'=>'Espèces','carte'=>'Carte','virement'=>'Virement','compte'=>'Compte'] as $v=>$l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $c['mode_paiement']===$v?'selected':''; ?>><?php echo $l; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Mettre à jour</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ─ MODAL INCIDENT ─ -->
            <div id="modal-incident-<?php echo $c['id']; ?>" class="modal-overlay hidden">
                <div class="modal modal-sm">
                    <div class="modal-header">
                        <h2><i class="fas fa-exclamation-triangle"></i> Signaler un Incident</h2>
                        <button class="modal-close" onclick="Taxi.closeModal('modal-incident-<?php echo $c['id']; ?>')">×</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_incident">
                        <input type="hidden" name="course_id" value="<?php echo $c['id']; ?>">
                        <div class="modal-body">
                            <div style="margin-bottom:.85rem;font-size:.82rem;color:var(--muted);">Course : <strong style="color:var(--text);"><?php echo htmlspecialchars($c['reference']); ?></strong></div>
                            <div class="form-grid" style="margin-bottom:.85rem;">
                                <div class="form-group">
                                    <label class="form-label">Type</label>
                                    <select name="type" class="form-select">
                                        <option value="accident">Accident</option>
                                        <option value="comportement">Comportement</option>
                                        <option value="materiel">Matériel</option>
                                        <option value="autre">Autre</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Gravité</label>
                                    <select name="gravite" class="form-select">
                                        <option value="mineure">Mineure</option>
                                        <option value="moyenne">Moyenne</option>
                                        <option value="grave">Grave</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Description *</label>
                                <textarea name="description" class="form-textarea" required placeholder="Décrivez l'incident..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" onclick="Taxi.closeModal('modal-incident-<?php echo $c['id']; ?>')" class="btn btn-secondary btn-sm">Annuler</button>
                            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-flag"></i> Signaler</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination" style="padding:1rem;">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page-1; ?>&statut=<?php echo urlencode($fStat); ?>&q=<?php echo urlencode($search); ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($i=max(1,$page-3); $i<=min($totalPages,$page+3); $i++): ?>
        <a href="?page=<?php echo $i; ?>&statut=<?php echo urlencode($fStat); ?>&q=<?php echo urlencode($search); ?>" class="page-btn <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page+1; ?>&statut=<?php echo urlencode($fStat); ?>&q=<?php echo urlencode($search); ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>