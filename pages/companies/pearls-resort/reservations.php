<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = "🏨 Pearl's Resort";
$companyColor   = '#d4a853';
$companyFaction = 'PEARLS_RESORT';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Réservations';
$db = Database::getInstance()->getConnection();
$message = ''; $messageType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'stock.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'stock.add');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'stock.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'stock.delete');

// ── CRÉER RÉSERVATION ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_reservation') {
    try {
        $roomId   = (int)$_POST['room_id'];
        $checkIn  = $_POST['check_in'];
        $checkOut = $_POST['check_out'];

        // Vérifier chambre libre
        $stmt = $db->prepare("SELECT price_per_night, status FROM pearls_rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();
        if (!$room) throw new Exception("Chambre introuvable");
        if ($room['status'] === 'occupée') throw new Exception("Cette chambre est déjà occupée");

        // Vérifier pas de chevauchement
        $stmt = $db->prepare("
            SELECT id FROM pearls_reservations
            WHERE room_id = ? AND status IN ('confirmée','en cours')
            AND NOT (check_out <= ? OR check_in >= ?)
        ");
        $stmt->execute([$roomId, $checkIn, $checkOut]);
        if ($stmt->fetch()) throw new Exception("Cette chambre est déjà réservée sur cette période");

        $nights = max(1, (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400));
        $total  = $nights * (float)$room['price_per_night'];

        $stmt = $db->prepare("
            INSERT INTO pearls_reservations (room_id, client_name, client_phone, check_in, check_out, total_amount, status, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 'confirmée', ?, ?)
        ");
        $stmt->execute([$roomId, trim($_POST['client_name']), trim($_POST['client_phone'] ?? ''), $checkIn, $checkOut, $total, trim($_POST['notes'] ?? ''), $_SESSION['user_id']]);

        // Si check-in = aujourd'hui → statut "en cours" et chambre occupée
        if ($checkIn <= date('Y-m-d')) {
            $newId = $db->lastInsertId();
            $db->prepare("UPDATE pearls_reservations SET status='en cours' WHERE id=?")->execute([$newId]);
            $db->prepare("UPDATE pearls_rooms SET status='occupée' WHERE id=?")->execute([$roomId]);
        }

        $message = "Réservation créée pour {$_POST['client_name']} — $nights nuit(s), total \$$total";
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur : ' . $e->getMessage(); $messageType = 'error';
    }
}

// ── CHANGER STATUT RÉSERVATION ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_reservation_status') {
    $resId     = (int)$_POST['res_id'];
    $newStatus = $_POST['new_status'] ?? '';
    $allowed   = ['confirmée','en cours','terminée','annulée','no-show'];
    if (in_array($newStatus, $allowed)) {
        $stmt = $db->prepare("SELECT room_id FROM pearls_reservations WHERE id=?");
        $stmt->execute([$resId]); $res = $stmt->fetch();
        $db->prepare("UPDATE pearls_reservations SET status=? WHERE id=?")->execute([$newStatus, $resId]);
        if ($res) {
            if ($newStatus === 'en cours') {
                $db->prepare("UPDATE pearls_rooms SET status='occupée' WHERE id=?")->execute([$res['room_id']]);
            } elseif (in_array($newStatus, ['terminée','annulée','no-show'])) {
                $db->prepare("UPDATE pearls_rooms SET status='nettoyage' WHERE id=?")->execute([$res['room_id']]);
            }
        }
        $message = "Statut de la réservation mis à jour."; $messageType = 'success';
    }
}

// ── SUPPRIMER ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_reservation' && $canManage) {
    $stmt = $db->prepare("SELECT room_id FROM pearls_reservations WHERE id=?");
    $stmt->execute([(int)$_POST['res_id']]); $r = $stmt->fetch();
    $db->prepare("DELETE FROM pearls_reservations WHERE id=?")->execute([(int)$_POST['res_id']]);
    if ($r) $db->prepare("UPDATE pearls_rooms SET status='libre' WHERE id=? AND status='occupée'")->execute([$r['room_id']]);
    $message = "Réservation supprimée."; $messageType = 'success';
}

// ── FILTRES ──
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['q'] ?? '');
$filterMonth  = $_GET['month'] ?? '';
$page  = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = []; $params = [];
if ($filterStatus) { $where[] = 'r.status = ?'; $params[] = $filterStatus; }
if ($filterSearch) { $where[] = 'r.client_name LIKE ?'; $params[] = "%$filterSearch%"; }
if ($filterMonth)  { $where[] = "DATE_FORMAT(r.check_in,'%Y-%m') = ?"; $params[] = $filterMonth; }
$wStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $db->prepare("SELECT COUNT(*) FROM pearls_reservations r $wStr");
$total->execute($params); $total = (int)$total->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$reservations = $db->prepare("
    SELECT r.*, rm.room_number, rm.room_type, rm.price_per_night
    FROM pearls_reservations r
    JOIN pearls_rooms rm ON r.room_id = rm.id
    $wStr ORDER BY r.created_at DESC LIMIT $perPage OFFSET $offset
");
$reservations->execute($params); $reservations = $reservations->fetchAll();

// Chambres libres pour le formulaire
$freeRooms = $db->query("SELECT id, room_number, room_type, price_per_night FROM pearls_rooms WHERE status IN ('libre') ORDER BY CAST(room_number AS UNSIGNED)")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <div>
        <p style="color:var(--text-2);font-size:.875rem;"><?php echo number_format($total); ?> réservation(s)</p>
    </div>
    <button onclick="Pearl.openModal('modal-create-res')" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouvelle réservation
    </button>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<!-- FILTRES -->
<div class="card" style="padding:1rem 1.25rem;margin-bottom:1.5rem;">
    <form method="GET" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <div class="search-bar" style="flex:1;min-width:180px;">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" name="q" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Rechercher un client…">
        </div>
        <select name="status" class="form-select" style="width:auto;padding:.65rem .9rem;">
            <option value="">Tous les statuts</option>
            <?php foreach (['confirmée','en cours','terminée','annulée','no-show'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $filterStatus===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="month" name="month" class="form-input" value="<?php echo $filterMonth; ?>" style="width:auto;">
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-filter"></i></button>
        <a href="reservations.php" class="btn btn-sm" style="background:none;border:1px solid var(--border);color:var(--text-2);">Reset</a>
    </form>
</div>

<!-- TABLE -->
<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th><th>Client</th><th>Chambre</th><th>Check-in</th><th>Check-out</th>
                    <th>Nuits</th><th>Total</th><th>Statut</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reservations)): ?>
                <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--text-2);">Aucune réservation</td></tr>
                <?php else: ?>
                <?php foreach ($reservations as $r):
                    $sm = [
                        'confirmée' => ['badge-blue',   'Confirmée'],
                        'en cours'  => ['badge-green',  'En cours'],
                        'terminée'  => ['badge-slate',  'Terminée'],
                        'annulée'   => ['badge-red',    'Annulée'],
                        'no-show'   => ['badge-orange', 'No-show'],
                    ][$r['status']] ?? ['badge-slate', $r['status']];
                ?>
                <tr>
                    <td style="font-family:'DM Mono',monospace;font-size:.72rem;color:var(--text-2);">#<?php echo $r['id']; ?></td>
                    <td style="font-weight:700;"><?php echo htmlspecialchars($r['client_name']); ?>
                        <?php if ($r['client_phone']): ?>
                        <div style="font-size:.72rem;color:var(--text-2);"><?php echo htmlspecialchars($r['client_phone']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-gold">N°<?php echo htmlspecialchars($r['room_number']); ?></span>
                        <div style="font-size:.7rem;color:var(--text-2);"><?php echo htmlspecialchars($r['room_type']); ?></div>
                    </td>
                    <td style="font-size:.8rem;font-family:'DM Mono',monospace;"><?php echo date('d/m/Y', strtotime($r['check_in'])); ?></td>
                    <td style="font-size:.8rem;font-family:'DM Mono',monospace;"><?php echo date('d/m/Y', strtotime($r['check_out'])); ?></td>
                    <td style="text-align:center;font-weight:700;"><?php echo $r['nights']; ?></td>
                    <td style="font-weight:700;color:var(--gold);">$<?php echo number_format($r['total_amount'], 0, ',', ' '); ?></td>
                    <td><span class="badge <?php echo $sm[0]; ?>"><?php echo $sm[1]; ?></span></td>
                    <td>
                        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                            <!-- Changer statut -->
                            <form method="POST" style="display:flex;align-items:center;gap:.3rem;">
                                <input type="hidden" name="action" value="update_reservation_status">
                                <input type="hidden" name="res_id" value="<?php echo $r['id']; ?>">
                                <select name="new_status" class="form-select" style="padding:.4rem .6rem;font-size:.75rem;width:auto;" onchange="this.form.submit()">
                                    <?php foreach (['confirmée','en cours','terminée','annulée','no-show'] as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php echo $r['status']===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <?php if ($canManage): ?>
                            <form method="POST" data-confirm="Supprimer cette réservation ?" style="display:inline;">
                                <input type="hidden" name="action" value="delete_reservation">
                                <input type="hidden" name="res_id" value="<?php echo $r['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="padding:.4rem .7rem;">
                                    <i class="fas fa-trash" style="font-size:.7rem;"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div style="padding:1rem 1.25rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:.83rem;">
        <span style="color:var(--text-2);">Page <?php echo $page; ?>/<?php echo $totalPages; ?></span>
        <div style="display:flex;gap:.4rem;">
            <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($filterSearch); ?>&status=<?php echo urlencode($filterStatus); ?>" class="btn btn-secondary btn-sm">← Préc.</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($filterSearch); ?>&status=<?php echo urlencode($filterStatus); ?>" class="btn btn-secondary btn-sm">Suiv. →</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── MODAL CRÉER RÉSERVATION ── -->
<div id="modal-create-res" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-calendar-plus"></i> Nouvelle réservation</h2>
            <button class="modal-close" onclick="Pearl.closeModal('modal-create-res')">×</button>
        </div>
        <form method="POST" id="resForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_reservation">
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Chambre *</label>
                        <select name="room_id" id="res-room-id" class="form-select" required onchange="updatePricePreview()">
                            <option value="">Sélectionner une chambre libre…</option>
                            <?php foreach ($freeRooms as $rm): ?>
                            <option value="<?php echo $rm['id']; ?>" data-price="<?php echo $rm['price_per_night']; ?>">
                                N°<?php echo htmlspecialchars($rm['room_number']); ?> — <?php echo htmlspecialchars($rm['room_type']); ?>
                                ($<?php echo number_format($rm['price_per_night'], 0, ',', ' '); ?>/nuit)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nom du client *</label>
                        <input type="text" name="client_name" class="form-input" placeholder="Prénom Nom" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Téléphone</label>
                        <input type="text" name="client_phone" class="form-input" placeholder="555-…">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Check-in *</label>
                        <input type="date" name="check_in" id="res-checkin" class="form-input" required onchange="updatePricePreview()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Check-out *</label>
                        <input type="date" name="check_out" id="res-checkout" class="form-input" required onchange="updatePricePreview()">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-textarea" rows="2" placeholder="Demandes particulières…"></textarea>
                    </div>
                </div>
                <!-- Aperçu du prix -->
                <div id="price-preview" style="display:none;background:rgba(212,168,83,.08);border:1px solid rgba(212,168,83,.2);border-radius:10px;padding:1rem;margin-top:.75rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-size:.83rem;color:var(--text-2);">Estimation :</span>
                        <span id="price-preview-val" style="font-size:1.1rem;font-weight:800;color:var(--gold);font-family:'DM Mono',monospace;"></span>
                    </div>
                    <div id="price-preview-detail" style="font-size:.72rem;color:var(--text-2);margin-top:.2rem;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Pearl.closeModal('modal-create-res')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer la réservation</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('res-checkin').min = new Date().toISOString().split('T')[0];

function updatePricePreview() {
    const sel    = document.getElementById('res-room-id');
    const checkin  = document.getElementById('res-checkin').value;
    const checkout = document.getElementById('res-checkout').value;
    const preview  = document.getElementById('price-preview');
    const previewVal = document.getElementById('price-preview-val');
    const previewDetail = document.getElementById('price-preview-detail');

    if (!sel.value || !checkin || !checkout) { preview.style.display = 'none'; return; }

    const price  = parseFloat(sel.options[sel.selectedIndex].dataset.price || 0);
    const nights = Math.max(0, Math.round((new Date(checkout) - new Date(checkin)) / 86400000));

    if (nights <= 0) { preview.style.display = 'none'; return; }

    const total = nights * price;
    preview.style.display = 'block';
    previewVal.textContent = '$' + total.toLocaleString('fr-FR');
    previewDetail.textContent = nights + ' nuit(s) × $' + price.toLocaleString('fr-FR') + '/nuit';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>