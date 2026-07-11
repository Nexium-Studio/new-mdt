<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction'] ?? '', ['LSPD', 'LSSD', 'DOJ'], true) &&
    !hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Plans tactiques';
$db        = Database::getInstance()->getConnection();

$maps    = [];
$dbError = null;
try {
    $sql = '
        SELECT m.id, m.title, m.nature, m.map_layer,
               m.created_at, m.updated_at, m.author_id,
               u.username,
               p.firstname AS profile_firstname,
               p.lastname  AS profile_lastname
        FROM lspd_maps m
        JOIN users u ON u.id = m.author_id
        LEFT JOIN profiles p ON p.id = u.profile_id
        ORDER BY m.updated_at DESC
    ';
    $maps = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

require_once __DIR__ . '/includes/map_api.php';
require_once __DIR__ . '/includes/lspd_map_assets.php';
$thumbUrls = lspd_map_thumb_urls();
$csrf      = lspd_map_ensure_csrf();

$natureLabels = [
    'operation'    => ['label' => 'Opération',    'icon' => 'fa-crosshairs',      'color' => '#ef4444'],
    'briefing'     => ['label' => 'Briefing',      'icon' => 'fa-chalkboard',      'color' => '#3b82f6'],
    'surveillance' => ['label' => 'Surveillance',  'icon' => 'fa-eye',             'color' => '#f97316'],
    'patrouille'   => ['label' => 'Patrouille',    'icon' => 'fa-shield-halved',   'color' => '#22c55e'],
    'enquete'      => ['label' => 'Enquête',       'icon' => 'fa-magnifying-glass','color' => '#a855f7'],
    'autre'        => ['label' => 'Autre',          'icon' => 'fa-map-pin',         'color' => '#64748b'],
];
$layerLabels = ['atlas' => 'Atlas', 'satellite' => 'Satellite', 'road' => 'Routes'];

include __DIR__ . '/includes/header.php';
?>
<style>
.maps-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1.75rem}

.maps-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));
  gap:1.1rem;
}

.map-card{
  border-radius:14px;
  border:1px solid var(--bdr);
  background:var(--card-bg, #0f172a);
  overflow:hidden;
  display:flex;flex-direction:column;
  transition:border-color .18s, box-shadow .18s, transform .18s;
}
.map-card:hover{
  border-color:var(--blue);
  box-shadow:0 6px 28px rgba(37,99,235,.18);
  transform:translateY(-2px);
}

.map-card-thumb{
  position:relative;height:120px;overflow:hidden;background:#020508;flex-shrink:0;
}
.map-card-thumb img{
  width:100%;height:100%;object-fit:cover;
  transition:transform .35s;display:block;
}
.map-card:hover .map-card-thumb img{transform:scale(1.04)}

.map-card-badge{
  position:absolute;top:10px;left:10px;
  padding:3px 9px;border-radius:20px;
  font-size:.67rem;font-weight:700;letter-spacing:.04em;
  background:rgba(3,8,15,.82);
  border:1px solid rgba(255,255,255,.12);
  display:flex;align-items:center;gap:.35rem;
  color:#e2e8f0;
}

.map-card-body{
  padding:1rem 1rem .85rem;
  display:flex;flex-direction:column;gap:.6rem;flex:1;
}
.map-card-title{
  font-size:.95rem;font-weight:700;color:var(--txt);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.map-card-meta{
  display:flex;flex-direction:column;gap:.2rem;
  font-size:.72rem;color:var(--txt-s);
}
.map-card-meta span{display:flex;align-items:center;gap:.4rem}
.map-card-meta i{width:12px;text-align:center;opacity:.6}

/* ═══ Pied : Consulter + Supprimer ═══ */
.map-card-foot{
  padding:.7rem 1rem;
  border-top:1px solid var(--bdr);
  display:flex;justify-content:space-between;align-items:center;gap:.5rem;
}
.btn-consult{
  display:inline-flex;align-items:center;gap:.45rem;
  padding:.5rem 1.1rem;border-radius:9px;
  background:rgba(37,99,235,.15);
  border:1px solid rgba(37,99,235,.35);
  color:var(--blue-xl, #93c5fd);
  font-size:.8rem;font-weight:600;text-decoration:none;
  transition:background .15s, border-color .15s;
}
.btn-consult:hover{
  background:rgba(37,99,235,.28);
  border-color:rgba(37,99,235,.65);color:#fff;
}
.btn-consult i{font-size:.75rem}

/* Bouton Modifier */
.btn-edit{
  width:36px;height:36px;border-radius:9px;
  background:rgba(234,179,8,.1);
  border:1px solid rgba(234,179,8,.3);
  color:#fde68a;
  cursor:pointer;text-decoration:none;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:.85rem;
  transition:background .15s, border-color .15s, color .15s;
}
.btn-edit:hover{
  background:rgba(234,179,8,.25);
  border-color:rgba(234,179,8,.55);
  color:#fff;
}

/* Bouton Supprimer */
.btn-delete{
  width:36px;height:36px;border-radius:9px;
  background:rgba(239,68,68,.1);
  border:1px solid rgba(239,68,68,.3);
  color:#fca5a5;
  cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:.85rem;
  transition:background .15s, border-color .15s, color .15s;
}
.btn-delete:hover{
  background:rgba(239,68,68,.25);
  border-color:rgba(239,68,68,.55);
  color:#fff;
}
.btn-delete:disabled{
  opacity:.5;cursor:not-allowed;
}

.map-card-actions{display:flex;gap:.4rem;align-items:center}

.maps-empty{
  grid-column:1/-1;text-align:center;padding:4rem 2rem;color:var(--txt-s);
}
.maps-empty i{font-size:2.8rem;opacity:.18;display:block;margin-bottom:1rem}

.maps-alert{
  padding:.85rem 1rem;border-radius:10px;
  background:rgba(245,158,11,.08);
  border:1px solid rgba(245,158,11,.25);
  color:#fde68a;font-size:.84rem;margin-bottom:1.25rem;
}

.maps-count{font-size:.75rem;color:var(--txt-s);margin-bottom:1rem}

/* ═══ Modal confirmation suppression ═══ */
.del-modal-overlay{
  display:none;position:fixed;inset:0;
  background:rgba(2,5,8,.75);
  z-index:9999;
  align-items:center;justify-content:center;
}
.del-modal-overlay.visible{display:flex}
.del-modal{
  background:var(--card-bg, #0f172a);
  border:1px solid var(--bdr);
  border-radius:14px;
  padding:1.5rem;
  max-width:420px;
  width:90%;
}
.del-modal h3{
  color:#fca5a5;font-size:1rem;margin:0 0 .5rem;
  display:flex;align-items:center;gap:.5rem;
}
.del-modal p{
  color:var(--txt-s);font-size:.85rem;margin:0 0 1rem;line-height:1.5;
}
.del-modal-name{
  color:var(--txt);font-weight:600;
  background:rgba(255,255,255,.04);
  padding:.35rem .7rem;border-radius:6px;
  display:inline-block;margin:.3rem 0;
}
.del-modal-actions{
  display:flex;gap:.6rem;justify-content:flex-end;margin-top:1rem;
}
.btn-cancel{
  padding:.55rem 1.2rem;border-radius:8px;
  background:transparent;border:1px solid var(--bdr);
  color:var(--txt-s);cursor:pointer;font-size:.82rem;
}
.btn-cancel:hover{background:rgba(255,255,255,.05);color:var(--txt)}
.btn-confirm-del{
  padding:.55rem 1.2rem;border-radius:8px;
  background:rgba(239,68,68,.2);
  border:1px solid rgba(239,68,68,.5);
  color:#fca5a5;cursor:pointer;font-size:.82rem;font-weight:600;
  display:inline-flex;align-items:center;gap:.4rem;
}
.btn-confirm-del:hover{
  background:rgba(239,68,68,.35);border-color:rgba(239,68,68,.8);color:#fff;
}
.btn-confirm-del:disabled{opacity:.5;cursor:not-allowed}
</style>

<!-- En-tête -->
<div class="maps-hd">
    <div>
        <div class="ls-topbar-title" style="position:static;padding:0;border:none">
            <i class="fas fa-map-location-dot"></i> Plans tactiques
        </div>
        <div class="ls-topbar-sub" style="margin-top:.35rem">
            Cartes opérationnelles, briefings et collaboration en temps réel
        </div>
    </div>
    <a href="add-map.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouveau plan
    </a>
</div>

<?php if ($dbError): ?>
<div class="maps-alert">
    <strong>Base de données :</strong> <?php echo htmlspecialchars($dbError); ?>
</div>
<?php endif; ?>

<?php if (!$dbError): ?>
<?php if (!empty($maps)): ?>
<div class="maps-count">
    <?php echo count($maps); ?> plan<?php echo count($maps) > 1 ? 's' : ''; ?> disponible<?php echo count($maps) > 1 ? 's' : ''; ?>
</div>
<?php endif; ?>

<div class="maps-grid">

    <?php if (empty($maps)): ?>
    <div class="maps-empty">
        <i class="fas fa-map"></i>
        <div style="font-weight:600;color:var(--txt);font-size:1rem">Aucun plan enregistré</div>
        <p style="margin-top:.5rem;font-size:.85rem">Créez votre premier plan tactique.</p>
        <a href="add-map.php" class="btn btn-primary" style="margin-top:1.25rem">
            <i class="fas fa-plus"></i> Créer un plan
        </a>
    </div>

    <?php else: ?>
    <?php foreach ($maps as $m):
        $author  = mdt_rp_display_name($m['profile_firstname'] ?? null, $m['profile_lastname'] ?? null, (string) $m['username']);
        $nat     = $natureLabels[$m['nature']] ?? $natureLabels['autre'];
        $layer   = in_array($m['map_layer'], ['atlas','road','satellite'], true) ? $m['map_layer'] : 'atlas';
        $thumb   = $thumbUrls[$layer] ?? '';
        $updated = date('d/m/Y H\hi', strtotime($m['updated_at']));
    ?>
    <div class="map-card" data-id="<?php echo (int)$m['id']; ?>">

        <div class="map-card-thumb">
            <?php if ($thumb): ?>
            <img src="<?php echo htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8'); ?>"
                 alt="<?php echo htmlspecialchars($layerLabels[$layer] ?? $layer); ?>" loading="lazy">
            <?php else: ?>
            <div style="width:100%;height:100%;background:linear-gradient(135deg,#0f172a,#1e293b);display:flex;align-items:center;justify-content:center">
                <i class="fas fa-map" style="font-size:2rem;opacity:.2;color:#e2e8f0"></i>
            </div>
            <?php endif; ?>

            <div class="map-card-badge">
                <i class="fas <?php echo $nat['icon']; ?>" style="color:<?php echo $nat['color']; ?>"></i>
                <?php echo $nat['label']; ?>
            </div>
        </div>

        <div class="map-card-body">
            <div class="map-card-title" title="<?php echo htmlspecialchars($m['title']); ?>">
                <?php echo htmlspecialchars($m['title']); ?>
            </div>
            <div class="map-card-meta">
                <span><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($layerLabels[$layer] ?? $layer); ?></span>
                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($author); ?></span>
                <span><i class="fas fa-clock"></i> Modifié le <?php echo $updated; ?></span>
            </div>
        </div>

        <!-- Pied : Consulter + Modifier + Supprimer -->
        <?php $canEdit = lspd_map_user_can_edit((int)$m['author_id']); ?>
        <div class="map-card-foot">
            <a class="btn-consult" href="map-view.php?id=<?php echo (int)$m['id']; ?>">
                <i class="fas fa-arrow-right"></i> Consulter
            </a>
            <div class="map-card-actions">
                <?php if ($canEdit): ?>
                <a class="btn-edit"
                   href="edit-map.php?id=<?php echo (int)$m['id']; ?>"
                   title="Modifier ce plan">
                    <i class="fas fa-pen-to-square"></i>
                </a>
                <?php endif; ?>
                <button type="button"
                        class="btn-delete js-del-btn"
                        data-id="<?php echo (int)$m['id']; ?>"
                        data-title="<?php echo htmlspecialchars($m['title'], ENT_QUOTES, 'UTF-8'); ?>"
                        title="Supprimer ce plan">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>

    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>
<?php endif; ?>

<!-- ═══ Modal de confirmation ═══ -->
<div class="del-modal-overlay" id="delModal">
    <div class="del-modal">
        <h3><i class="fas fa-triangle-exclamation"></i> Supprimer le plan</h3>
        <p>
            Cette action est <strong>définitive</strong>. Le plan et tous ses tracés seront perdus.
        </p>
        <p>
            Plan à supprimer :
            <span class="del-modal-name" id="delModalName">...</span>
        </p>
        <div id="delModalError" style="color:#ef4444;font-size:.78rem;margin-top:.5rem;display:none"></div>
        <div class="del-modal-actions">
            <button type="button" class="btn-cancel"      id="delCancel">Annuler</button>
            <button type="button" class="btn-confirm-del" id="delConfirm">
                <i class="fas fa-trash"></i> Supprimer
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var CSRF = <?php echo json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var AJAX = <?php echo json_encode(rtrim(SITE_URL, '/') . '/pages/lspd/ajax/', JSON_HEX_TAG); ?>;

    var modal    = document.getElementById('delModal');
    var nameEl   = document.getElementById('delModalName');
    var errEl    = document.getElementById('delModalError');
    var btnOk    = document.getElementById('delConfirm');
    var btnCncl  = document.getElementById('delCancel');
    var currentId = null;

    function openModal(id, title) {
        currentId = id;
        if (nameEl) nameEl.textContent = title;
        if (errEl) errEl.style.display = 'none';
        if (btnOk) {
            btnOk.disabled = false;
            btnOk.innerHTML = '<i class="fas fa-trash"></i> Supprimer';
        }
        if (modal) modal.classList.add('visible');
    }

    function closeModal() {
        if (modal) modal.classList.remove('visible');
        currentId = null;
    }

    /* Ouvre la modal au clic sur trash */
    document.querySelectorAll('.js-del-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(parseInt(btn.dataset.id, 10), btn.dataset.title || 'Sans titre');
        });
    });

    /* Annuler */
    if (btnCncl) btnCncl.addEventListener('click', closeModal);
    if (modal) modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    /* Confirmer */
    if (btnOk) {
        btnOk.addEventListener('click', function () {
            if (!currentId) return;
            if (errEl) errEl.style.display = 'none';
            btnOk.disabled = true;
            btnOk.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Suppression\u2026';

            fetch(AJAX + 'map_delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ map_id: currentId, csrf: CSRF }),
            })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) throw new Error(j.error || 'Erreur serveur');
                /* Retirer la carte du DOM */
                var card = document.querySelector('.map-card[data-id="' + currentId + '"]');
                if (card && card.parentNode) card.parentNode.removeChild(card);
                closeModal();
                /* Recharger si plus aucune carte visible (pour afficher l'état vide) */
                if (!document.querySelector('.map-card')) window.location.reload();
            })
            .catch(function (err) {
                if (errEl) {
                    errEl.textContent = err.message || 'Impossible de supprimer.';
                    errEl.style.display = 'block';
                }
                btnOk.disabled = false;
                btnOk.innerHTML = '<i class="fas fa-trash"></i> Supprimer';
            });
        });
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>