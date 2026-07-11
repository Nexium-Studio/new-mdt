<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = "🏨 Pearl's Resort";
$companyColor   = '#d4a853';
$companyFaction = 'PEARLS_RESORT';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Gestion des Chambres';
$db = Database::getInstance()->getConnection();
$message = ''; $messageType = '';

// Permissions — RBAC granulaire (chambres = stock)
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'stock.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'stock.add');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'stock.edit');

// ── AJOUTER CHAMBRE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_room' && $canManage) {
    try {
        $amenities = json_encode(array_filter(array_map('trim', explode(',', $_POST['amenities'] ?? ''))));
        $stmt = $db->prepare("
            INSERT INTO pearls_rooms (room_number, room_type, floor, capacity, price_per_night, status, amenities, description, created_by)
            VALUES (?, ?, ?, ?, ?, 'libre', ?, ?, ?)
        ");
        $stmt->execute([
            trim($_POST['room_number']),
            $_POST['room_type'],
            (int)$_POST['floor'],
            (int)$_POST['capacity'],
            (float)$_POST['price_per_night'],
            $amenities,
            trim($_POST['description'] ?? ''),
            $_SESSION['user_id'],
        ]);
        $message = 'Chambre ' . $_POST['room_number'] . ' créée avec succès.';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur : ' . $e->getMessage();
        $messageType = 'error';
    }
}

// ── MODIFIER CHAMBRE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_room' && $canManage) {
    try {
        $amenities = json_encode(array_filter(array_map('trim', explode(',', $_POST['amenities'] ?? ''))));
        $stmt = $db->prepare("
            UPDATE pearls_rooms SET room_type=?, floor=?, capacity=?, price_per_night=?, amenities=?, description=?
            WHERE id=?
        ");
        $stmt->execute([
            $_POST['room_type'],
            (int)$_POST['floor'],
            (int)$_POST['capacity'],
            (float)$_POST['price_per_night'],
            $amenities,
            trim($_POST['description'] ?? ''),
            (int)$_POST['room_id'],
        ]);
        $message = 'Chambre mise à jour.';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur : ' . $e->getMessage(); $messageType = 'error';
    }
}

// ── CHANGER STATUT ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_status') {
    $allowedStatuses = ['libre','nettoyage','maintenance'];
    $newStatus = $_POST['new_status'] ?? '';
    $roomId    = (int)$_POST['room_id'];
    if (in_array($newStatus, $allowedStatuses)) {
        $db->prepare("UPDATE pearls_rooms SET status=? WHERE id=?")->execute([$newStatus, $roomId]);
        // Si on remet libre, on termine les réservations en cours
        if ($newStatus === 'libre') {
            $db->prepare("UPDATE pearls_reservations SET status='terminée' WHERE room_id=? AND status='en cours'")->execute([$roomId]);
        }
        $message = 'Statut de la chambre mis à jour.';
        $messageType = 'success';
    }
}

// ── SUPPRIMER CHAMBRE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_room' && $canManage) {
    $db->prepare("DELETE FROM pearls_rooms WHERE id=?")->execute([(int)$_POST['room_id']]);
    $message = 'Chambre supprimée.'; $messageType = 'success';
}

// ── RÉCUPÉRER CHAMBRES ──
$filterFloor  = $_GET['floor']  ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type']   ?? '';

$where = []; $params = [];
if ($filterFloor)  { $where[] = 'floor = ?';     $params[] = $filterFloor; }
if ($filterStatus) { $where[] = 'status = ?';    $params[] = $filterStatus; }
if ($filterType)   { $where[] = 'room_type = ?'; $params[] = $filterType; }
$wStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rooms = $db->prepare("SELECT * FROM pearls_rooms $wStr ORDER BY CAST(room_number AS UNSIGNED), room_number");
$rooms->execute($params);
$rooms = $rooms->fetchAll();

// Grouper par étage
$byFloor = [];
foreach ($rooms as $r) $byFloor[$r['floor']][] = $r;

// Réservation en cours par chambre
$activeResMap = [];
$activeResList = $db->query("
    SELECT r.*, rm.id as rid
    FROM pearls_reservations r
    JOIN pearls_rooms rm ON r.room_id = rm.id
    WHERE r.status = 'en cours'
")->fetchAll();
foreach ($activeResList as $ar) $activeResMap[$ar['rid']] = $ar;

$floors  = $db->query("SELECT DISTINCT floor FROM pearls_rooms ORDER BY floor")->fetchAll(PDO::FETCH_COLUMN);
$types   = ['Standard','Supérieure','Deluxe','Suite','Penthouse'];

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── STATUS HELPERS ── */
.room-card { position:relative; border-radius:16px; padding:1.25rem; border:1px solid; transition:all .25s; cursor:pointer; }
.room-card:hover { transform:translateY(-3px); box-shadow:0 8px 30px rgba(0,0,0,.3); }

.room-card.libre      { background:rgba(16,185,129,.06);  border-color:rgba(16,185,129,.25);  }
.room-card.occupee    { background:rgba(239,68,68,.06);   border-color:rgba(239,68,68,.25);   }
.room-card.nettoyage  { background:rgba(234,179,8,.06);   border-color:rgba(234,179,8,.25);   }
.room-card.maintenance{ background:rgba(100,116,139,.06); border-color:rgba(100,116,139,.25); }

.room-status-dot {
    width:9px; height:9px; border-radius:50%; flex-shrink:0;
    box-shadow:0 0 6px currentColor;
}
.dot-libre      { background:#10b981; color:#10b981; }
.dot-occupee    { background:#ef4444; color:#ef4444; }
.dot-nettoyage  { background:#eab308; color:#eab308; }
.dot-maintenance{ background:#94a3b8; color:#94a3b8; }

.room-number {
    font-family:'DM Mono',monospace; font-size:1.35rem; font-weight:700; line-height:1;
}

.room-type-badge {
    font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em;
    padding:2px 8px; border-radius:5px;
}

.room-price { font-size:.9rem; font-weight:700; color:var(--gold); font-family:'DM Mono',monospace; }

.room-amenity {
    font-size:.62rem; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08);
    padding:2px 7px; border-radius:5px; color:var(--text-2); white-space:nowrap;
}

.floor-label {
    font-family:'Cormorant Garamond',serif; font-size:1.1rem; font-weight:700; color:var(--gold);
    display:flex; align-items:center; gap:.75rem; margin-bottom:1rem; padding-bottom:.5rem;
    border-bottom:1px solid var(--border);
}

.rooms-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:1rem; margin-bottom:2rem; }

/* Legend */
.legend { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.legend-item { display:flex; align-items:center; gap:.5rem; font-size:.78rem; color:var(--text-2); }
</style>

<!-- ACTIONS BAR -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <div class="legend">
        <?php foreach ([
            ['libre','#10b981','Libre'],
            ['occupée','#ef4444','Occupée'],
            ['nettoyage','#eab308','Nettoyage'],
            ['maintenance','#94a3b8','Maintenance'],
        ] as [$key,$color,$label]): ?>
        <div class="legend-item">
            <span style="width:10px;height:10px;border-radius:50%;background:<?php echo $color; ?>;box-shadow:0 0 6px <?php echo $color; ?>;flex-shrink:0;"></span>
            <?php echo $label; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($canManage): ?>
    <button onclick="Pearl.openModal('modal-add-room')" class="btn btn-primary">
        <i class="fas fa-plus"></i> Ajouter une chambre
    </button>
    <?php endif; ?>
</div>

<!-- FILTRES -->
<div class="card" style="padding:1rem 1.25rem;margin-bottom:1.5rem;">
    <form method="GET" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <select name="floor" class="form-select" style="width:auto;padding:.6rem .9rem;">
            <option value="">Tous les étages</option>
            <?php foreach ($floors as $f): ?>
            <option value="<?php echo $f; ?>" <?php echo $filterFloor==$f?'selected':''; ?>>Étage <?php echo $f; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type" class="form-select" style="width:auto;padding:.6rem .9rem;">
            <option value="">Tous les types</option>
            <?php foreach ($types as $t): ?>
            <option value="<?php echo $t; ?>" <?php echo $filterType===$t?'selected':''; ?>><?php echo $t; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-select" style="width:auto;padding:.6rem .9rem;">
            <option value="">Tous les statuts</option>
            <?php foreach (['libre','occupée','nettoyage','maintenance'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $filterStatus===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-filter"></i> Filtrer</button>
        <a href="chambres.php" class="btn btn-sm" style="background:none;border:1px solid var(--border);color:var(--text-2);">Reset</a>
        <span style="margin-left:auto;font-size:.8rem;color:var(--text-2);"><?php echo count($rooms); ?> chambre(s)</span>
    </form>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if (empty($rooms)): ?>
<div class="card"><div class="empty-state"><i class="fas fa-bed"></i><p>Aucune chambre trouvée</p></div></div>
<?php else: ?>

<?php foreach ($byFloor as $floor => $floorRooms): ?>
<div>
    <div class="floor-label">
        <i class="fas fa-layer-group" style="font-size:.9rem;opacity:.6;"></i>
        Étage <?php echo $floor; ?>
        <span style="font-size:.75rem;color:var(--text-2);font-weight:400;margin-left:.5rem;"><?php echo count($floorRooms); ?> chambre(s)</span>
    </div>
    <div class="rooms-grid">
        <?php foreach ($floorRooms as $room):
            $statusKey = str_replace('é','e', str_replace('é','e', $room['status'])); // occupée→occupee
            $statusKey = $room['status'] === 'occupée' ? 'occupee' : $room['status'];
            $amenities  = json_decode($room['amenities'] ?? '[]', true) ?: [];
            $activeRes  = $activeResMap[$room['id']] ?? null;

            $typeMeta = [
                'Standard'   => ['color'=>'#94a3b8','bg'=>'rgba(100,116,139,.15)'],
                'Supérieure' => ['color'=>'#3b82f6','bg'=>'rgba(59,130,246,.15)'],
                'Deluxe'     => ['color'=>'#8b5cf6','bg'=>'rgba(139,92,246,.15)'],
                'Suite'      => ['color'=>'#d4a853','bg'=>'rgba(212,168,83,.15)'],
                'Penthouse'  => ['color'=>'#ef4444','bg'=>'rgba(239,68,68,.15)'],
            ][$room['room_type']] ?? ['color'=>'#94a3b8','bg'=>'rgba(100,116,139,.15)'];
        ?>
        <div class="room-card <?php echo $statusKey; ?>"
             onclick="showRoomDetail(<?php echo htmlspecialchars(json_encode($room)); ?>, <?php echo htmlspecialchars(json_encode($activeRes)); ?>)">
            <!-- Header -->
            <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.75rem;">
                <div>
                    <div class="room-number"><?php echo htmlspecialchars($room['room_number']); ?></div>
                    <div style="margin-top:.25rem;">
                        <span class="room-type-badge" style="background:<?php echo $typeMeta['bg']; ?>;color:<?php echo $typeMeta['color']; ?>;">
                            <?php echo htmlspecialchars($room['room_type']); ?>
                        </span>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.35rem;">
                    <span class="room-status-dot dot-<?php echo $statusKey; ?>"></span>
                    <div style="font-size:.7rem;color:var(--text-2);font-weight:600;text-transform:capitalize;"><?php echo $room['status']; ?></div>
                </div>
            </div>

            <!-- Infos -->
            <div style="display:flex;justify-content:space-between;margin-bottom:.75rem;">
                <div class="room-price">$<?php echo number_format($room['price_per_night'], 0, ',', ' '); ?>/nuit</div>
                <div style="font-size:.75rem;color:var(--text-2);">
                    <i class="fas fa-user" style="margin-right:.25rem;"></i><?php echo $room['capacity']; ?> pers.
                </div>
            </div>

            <!-- Client si occupée -->
            <?php if ($activeRes): ?>
            <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:.6rem .75rem;margin-bottom:.75rem;">
                <div style="font-size:.72rem;color:#fca5a5;font-weight:700;">
                    <i class="fas fa-user-check" style="margin-right:.3rem;"></i><?php echo htmlspecialchars($activeRes['client_name']); ?>
                </div>
                <div style="font-size:.68rem;color:var(--text-2);">
                    Départ: <?php echo date('d/m/Y', strtotime($activeRes['check_out'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Commodités -->
            <?php if (!empty($amenities)): ?>
            <div style="display:flex;flex-wrap:wrap;gap:.3rem;">
                <?php foreach (array_slice($amenities, 0, 3) as $a): ?>
                <span class="room-amenity"><?php echo htmlspecialchars($a); ?></span>
                <?php endforeach; ?>
                <?php if (count($amenities) > 3): ?>
                <span class="room-amenity">+<?php echo count($amenities)-3; ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ── MODAL DÉTAIL CHAMBRE ── -->
<div id="modal-room-detail" class="modal-overlay hidden">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <h2><i class="fas fa-bed"></i> <span id="detail-title">Chambre</span></h2>
            <button class="modal-close" onclick="Pearl.closeModal('modal-room-detail')">×</button>
        </div>
        <div class="modal-body" id="detail-body"><!-- JS --></div>
        <div class="modal-footer" id="detail-footer"><!-- JS --></div>
    </div>
</div>

<!-- ── MODAL AJOUTER ── -->
<?php if ($canManage): ?>
<div id="modal-add-room" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Ajouter une chambre</h2>
            <button class="modal-close" onclick="Pearl.closeModal('modal-add-room')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_room">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">N° de chambre *</label>
                        <input type="text" name="room_number" class="form-input" placeholder="Ex: 101" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Étage *</label>
                        <input type="number" name="floor" class="form-input" min="0" max="99" value="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type *</label>
                        <select name="room_type" class="form-select">
                            <?php foreach ($types as $t): ?>
                            <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Capacité (pers.) *</label>
                        <input type="number" name="capacity" class="form-input" min="1" max="20" value="2" required>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Prix / nuit ($) *</label>
                        <input type="number" name="price_per_night" class="form-input" min="0" step="100" required>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Commodités <span style="font-weight:400;text-transform:none;">(séparées par virgule)</span></label>
                        <input type="text" name="amenities" class="form-input" placeholder="WiFi, TV, Climatisation, Mini-bar, Jacuzzi…">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" placeholder="Décrivez la chambre…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Pearl.closeModal('modal-add-room')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer la chambre</button>
            </div>
        </form>
    </div>
</div>

<!-- ── MODAL ÉDITER ── -->
<div id="modal-edit-room" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Modifier la chambre</h2>
            <button class="modal-close" onclick="Pearl.closeModal('modal-edit-room')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_room">
                <input type="hidden" name="room_id" id="edit-room-id">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Type *</label>
                        <select name="room_type" id="edit-room-type" class="form-select">
                            <?php foreach ($types as $t): ?>
                            <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Étage *</label>
                        <input type="number" name="floor" id="edit-room-floor" class="form-input" min="0" max="99" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Capacité *</label>
                        <input type="number" name="capacity" id="edit-room-capacity" class="form-input" min="1" max="20" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prix / nuit ($) *</label>
                        <input type="number" name="price_per_night" id="edit-room-price" class="form-input" min="0" step="100" required>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Commodités (virgule)</label>
                        <input type="text" name="amenities" id="edit-room-amenities" class="form-input">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit-room-desc" class="form-textarea"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Pearl.closeModal('modal-edit-room')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── MODAL CHANGER STATUT ── -->
<div id="modal-status" class="modal-overlay hidden">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <h2><i class="fas fa-exchange-alt"></i> Changer le statut</h2>
            <button class="modal-close" onclick="Pearl.closeModal('modal-status')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="room_id" id="status-room-id">
                <p style="color:var(--text-2);font-size:.875rem;margin-bottom:1.25rem;">
                    Chambre <strong id="status-room-number" style="color:var(--text);"></strong> — Statut actuel :
                    <span id="status-room-current" style="color:var(--gold);font-weight:700;"></span>
                </p>
                <div class="form-group">
                    <label class="form-label">Nouveau statut</label>
                    <select name="new_status" id="status-room-new" class="form-select">
                        <option value="libre">🟢 Libre</option>
                        <option value="nettoyage">🟡 En nettoyage</option>
                        <option value="maintenance">⚪ Maintenance</option>
                    </select>
                </div>
                <div class="alert alert-warning" style="margin-top:1rem;margin-bottom:0;">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>Pour marquer une chambre comme "occupée", créez une réservation.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Pearl.closeModal('modal-status')">Annuler</button>
                <button type="submit" class="btn btn-primary">Confirmer</button>
            </div>
        </form>
    </div>
</div>

<script>
const CAN_MANAGE = <?php echo $canManage ? 'true' : 'false'; ?>;

const typeColors = {
    'Standard':  '#94a3b8', 'Supérieure': '#3b82f6',
    'Deluxe':    '#8b5cf6', 'Suite':      '#d4a853', 'Penthouse': '#ef4444'
};

function showRoomDetail(room, activeRes) {
    const statusColors = { libre:'#10b981', 'occupée':'#ef4444', nettoyage:'#eab308', maintenance:'#94a3b8' };
    const statusColor  = statusColors[room.status] || '#94a3b8';
    const amenities    = JSON.parse(room.amenities || '[]');
    const typeColor    = typeColors[room.room_type] || '#94a3b8';

    document.getElementById('detail-title').textContent = 'Chambre ' + room.room_number;

    let clientHtml = '';
    if (activeRes) {
        clientHtml = `
        <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:12px;padding:1rem;margin-bottom:1rem;">
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#fca5a5;margin-bottom:.5rem;">
                <i class="fas fa-user-check" style="margin-right:.3rem;"></i>Client actuel
            </div>
            <div style="font-size:1rem;font-weight:700;">${escHtml(activeRes.client_name)}</div>
            <div style="font-size:.78rem;color:var(--text-2);margin-top:.2rem;">
                Arrivée: ${activeRes.check_in} · Départ: ${activeRes.check_out}
                · Total: $${Number(activeRes.total_amount).toLocaleString('fr-FR')}
            </div>
        </div>`;
    }

    document.getElementById('detail-body').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem;">
            <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:.9rem;text-align:center;">
                <div style="font-size:.65rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.3rem;">Type</div>
                <div style="font-weight:700;color:${typeColor};">${escHtml(room.room_type)}</div>
            </div>
            <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:.9rem;text-align:center;">
                <div style="font-size:.65rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.3rem;">Statut</div>
                <div style="font-weight:700;color:${statusColor};text-transform:capitalize;">${escHtml(room.status)}</div>
            </div>
            <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:.9rem;text-align:center;">
                <div style="font-size:.65rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.3rem;">Prix / nuit</div>
                <div style="font-weight:700;color:var(--gold);">$${Number(room.price_per_night).toLocaleString('fr-FR')}</div>
            </div>
            <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:.9rem;text-align:center;">
                <div style="font-size:.65rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.3rem;">Capacité</div>
                <div style="font-weight:700;">${escHtml(room.capacity)} pers.</div>
            </div>
        </div>
        ${clientHtml}
        ${amenities.length ? `
        <div style="margin-bottom:.75rem;">
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2);margin-bottom:.5rem;">Commodités</div>
            <div style="display:flex;flex-wrap:wrap;gap:.35rem;">
                ${amenities.map(a => `<span class="room-amenity">${escHtml(a)}</span>`).join('')}
            </div>
        </div>` : ''}
        ${room.description ? `<p style="font-size:.83rem;color:var(--text-2);line-height:1.5;">${escHtml(room.description)}</p>` : ''}
    `;

    let footer = '';
    if (room.status !== 'occupée') {
        footer += `<button class="btn btn-secondary btn-sm" onclick="openStatusModal(${room.id},'${escHtml(room.room_number)}','${escHtml(room.status)}')">
            <i class="fas fa-exchange-alt"></i> Statut</button>`;
    }
    if (CAN_MANAGE) {
        footer += `<button class="btn btn-secondary btn-sm" onclick="openEditModal(${JSON.stringify(room).replace(/"/g,'&quot;')})">
            <i class="fas fa-edit"></i> Modifier</button>`;
        footer += `<form method="POST" style="display:inline;" data-confirm="Supprimer cette chambre ?">
            <input type="hidden" name="action" value="delete_room">
            <input type="hidden" name="room_id" value="${room.id}">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Supprimer</button>
        </form>`;
    }
    footer += `<button class="btn btn-secondary btn-sm" onclick="Pearl.closeModal('modal-room-detail')">Fermer</button>`;
    document.getElementById('detail-footer').innerHTML = footer;

    Pearl.openModal('modal-room-detail');
}

function openStatusModal(id, number, current) {
    Pearl.closeModal('modal-room-detail');
    document.getElementById('status-room-id').value      = id;
    document.getElementById('status-room-number').textContent = number;
    document.getElementById('status-room-current').textContent = current;
    Pearl.openModal('modal-status');
}

function openEditModal(room) {
    Pearl.closeModal('modal-room-detail');
    const amenities = JSON.parse(room.amenities || '[]');
    document.getElementById('edit-room-id').value       = room.id;
    document.getElementById('edit-room-type').value     = room.room_type;
    document.getElementById('edit-room-floor').value    = room.floor;
    document.getElementById('edit-room-capacity').value = room.capacity;
    document.getElementById('edit-room-price').value    = room.price_per_night;
    document.getElementById('edit-room-amenities').value= amenities.join(', ');
    document.getElementById('edit-room-desc').value     = room.description || '';
    Pearl.openModal('modal-edit-room');
}

function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>