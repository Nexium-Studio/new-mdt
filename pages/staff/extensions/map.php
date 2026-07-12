<?php
/**
 * pages/staff/extensions/map.php
 * ─────────────────────────────────────────────────────────────────────────
 * Configuration staff du Système de Cartes MDT :
 *  - activation / désactivation des cartes de faction (apparition navbar),
 *  - modal par faction : matrice de permissions (View/Modifier/Créer/Supprimer)
 *    + gestion des tags (catégories).
 *
 * AJAX auto-contenu via ?action= (JSON {ok,data|error}).
 */

define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
require_once __DIR__ . '/../../maps/includes/map_bootstrap.php';
require_once __DIR__ . '/../../maps/includes/map_access.php';
mdt_maps_bootstrap($db);

/* ═══════════════════════════════════════════════════════════════
 *  ENDPOINTS AJAX (avant tout HTML)
 * ═══════════════════════════════════════════════════════════════ */
$__action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($__action !== '') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $needsCsrf = in_array($__action, ['toggle_faction', 'save_permissions', 'tag_create', 'tag_delete'], true);
    if ($needsCsrf && !mdt_map_verify_csrf((string) ($body['csrf'] ?? ''))) {
        maps_json_err('Session expirée, rechargez la page.', 419);
    }

    try {
        switch ($__action) {

            case 'get_permissions': {
                $owner = strtoupper(trim((string) ($_GET['owner_faction'] ?? '')));
                if (!in_array($owner, maps_service_faction_keys(), true)) {
                    maps_json_err('Faction inconnue.');
                }
                $current = maps_permissions_for($db, $owner);
                $meta    = maps_faction_meta($db);
                $list    = [];
                foreach ($meta as $k => $m) {
                    if ($k === $owner) {
                        continue;
                    }
                    $g = $current[$k] ?? ['can_view' => 0, 'can_edit' => 0, 'can_create' => 0, 'can_delete' => 0];
                    $list[] = [
                        'faction' => $k,
                        'name'    => $m['name'],
                        'color'   => $m['color'],
                        'view'    => (int) $g['can_view'],
                        'edit'    => (int) $g['can_edit'],
                        'create'  => (int) $g['can_create'],
                        'delete'  => (int) $g['can_delete'],
                    ];
                }
                maps_json_ok(['owner' => $owner, 'grantees' => $list]);
                break;
            }

            case 'toggle_faction': {
                $fk = strtoupper(trim((string) ($body['faction_key'] ?? '')));
                if (!in_array($fk, maps_service_faction_keys(), true)) {
                    maps_json_err('Faction inconnue.');
                }
                $active = !empty($body['active']) ? 1 : 0;
                $db->prepare("INSERT INTO mdt_map_factions (faction_key, is_active) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)")
                   ->execute([$fk, $active]);
                maps_json_ok(['faction_key' => $fk, 'is_active' => $active]);
                break;
            }

            case 'save_permissions': {
                $owner = strtoupper(trim((string) ($body['owner_faction'] ?? '')));
                if (!in_array($owner, maps_service_faction_keys(), true)) {
                    maps_json_err('Faction inconnue.');
                }
                $perms = is_array($body['perms'] ?? null) ? $body['perms'] : [];
                $db->beginTransaction();
                $db->prepare("DELETE FROM mdt_map_permissions WHERE owner_faction = ?")->execute([$owner]);
                $ins = $db->prepare("INSERT INTO mdt_map_permissions
                    (owner_faction, faction, can_view, can_edit, can_create, can_delete)
                    VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($perms as $p) {
                    $gf = strtoupper(trim((string) ($p['faction'] ?? '')));
                    if ($gf === '' || $gf === $owner || !in_array($gf, maps_service_faction_keys(), true)) {
                        continue;
                    }
                    $v = !empty($p['view'])   ? 1 : 0;
                    $e = !empty($p['edit'])   ? 1 : 0;
                    $c = !empty($p['create']) ? 1 : 0;
                    $d = !empty($p['delete']) ? 1 : 0;
                    if (!($v || $e || $c || $d)) {
                        continue; // ligne vide → non stockée
                    }
                    $ins->execute([$owner, $gf, $v, $e, $c, $d]);
                }
                $db->commit();
                maps_json_ok();
                break;
            }

            case 'tag_list': {
                $rows = $db->query("SELECT id, nom, color FROM mdt_map_tags ORDER BY nom ASC")
                           ->fetchAll(PDO::FETCH_ASSOC);
                maps_json_ok(['tags' => $rows]);
                break;
            }

            case 'tag_create': {
                $nom = trim((string) ($body['nom'] ?? ''));
                if ($nom === '') {
                    maps_json_err('Nom du tag requis.');
                }
                $color = trim((string) ($body['color'] ?? '#64748b'));
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                    $color = '#64748b';
                }
                $nom = mb_substr($nom, 0, 80);
                $db->prepare("INSERT INTO mdt_map_tags (nom, color) VALUES (?, ?)")->execute([$nom, $color]);
                maps_json_ok(['id' => (int) $db->lastInsertId(), 'nom' => $nom, 'color' => $color]);
                break;
            }

            case 'tag_delete': {
                $id = (int) ($body['id'] ?? 0);
                if ($id <= 0) {
                    maps_json_err('Identifiant invalide.');
                }
                $db->prepare("DELETE FROM mdt_map_tags WHERE id = ?")->execute([$id]);
                $db->prepare("UPDATE mdt_maps SET tag_id = NULL WHERE tag_id = ?")->execute([$id]);
                maps_json_ok();
                break;
            }

            default:
                maps_json_err('Action inconnue.', 404);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[MDT Maps][staff] ' . $e->getMessage());
        maps_json_err('Erreur serveur.', 500);
    }
    exit;
}

/* ═══════════════════════════════════════════════════════════════
 *  DONNÉES POUR LE RENDU
 * ═══════════════════════════════════════════════════════════════ */
$pageTitle  = 'Système de Cartes';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Système de Cartes']];
$csrf       = mdt_map_csrf();

$factionMeta  = maps_faction_meta($db);
$activeStates = [];
try {
    foreach ($db->query("SELECT faction_key, is_active FROM mdt_map_factions")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $activeStates[$r['faction_key']] = (int) $r['is_active'];
    }
} catch (Throwable $e) {
}
$activeCount = count(array_filter($activeStates));

/* Petit helper de rendu du visuel (logo MDT ou icône FA) */
function map_fac_visual(array $m): string
{
    $color = htmlspecialchars($m['color']);
    if (!empty($m['logo'])) {
        return '<div class="p-icon" style="background:' . $color . '18;padding:6px;">'
             . '<img src="' . htmlspecialchars($m['logo']) . '" alt="" referrerpolicy="no-referrer" '
             . 'style="width:100%;height:100%;object-fit:contain;max-width:28px;max-height:28px;">'
             . '</div>';
    }
    return '<div class="p-icon" style="background:' . $color . '18;color:' . $color . ';">'
         . '<i class="fa-solid fa-' . htmlspecialchars($m['icon']) . '"></i></div>';
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1><i class="fa-solid fa-map-location-dot" style="color:var(--green);margin-right:10px;"></i> Système de Cartes</h1>
        <p>Activez les cartes par faction, définissez les permissions et gérez les tags.</p>
    </div>
    <div class="page-header-right">
        <button class="btn btn-secondary" onclick="openTagsModal()"><i class="fa-solid fa-tags"></i> Gérer les tags</button>
        <a href="<?php echo SITE_URL; ?>/pages/maps/index.php" target="_blank" class="btn btn-primary">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Ouvrir les cartes
        </a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card" style="--accent-color:var(--green)">
        <div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div>
        <div class="stat-info">
            <div class="stat-label">Cartes de faction actives</div>
            <div class="stat-value"><?php echo $activeCount; ?>/<?php echo count($factionMeta); ?></div>
            <div class="stat-meta">Visibles dans la navbar des cartes</div>
        </div>
    </div>
    <div class="stat-card" style="--accent-color:var(--blue)">
        <div class="stat-icon"><i class="fa-solid fa-globe"></i></div>
        <div class="stat-info">
            <div class="stat-label">Cartes publiques</div>
            <div class="stat-value">2</div>
            <div class="stat-meta">Commune &amp; Gang — temps réel</div>
        </div>
    </div>
</div>

<div class="alert alert-info" style="margin-bottom:24px;">
    <i class="fa-solid fa-circle-info"></i>
    <span>Activer une faction ajoute sa carte dans la navbar de <code>/pages/maps</code>. Cliquez sur une carte pour définir quelles factions y ont accès (View / Modifier / Créer / Supprimer) et gérer les tags.</span>
</div>

<!-- ══ CARTES PUBLIQUES ══ -->
<div class="nav-section-label" style="padding-left:0;margin-bottom:10px;">Cartes publiques (toujours actives)</div>
<div class="grid-2" style="margin-bottom:28px;">
    <div class="panel-item active">
        <div class="p-icon" style="background:rgba(59,130,246,.15);color:var(--blue);"><i class="fa-solid fa-city"></i></div>
        <div class="p-info">
            <div class="p-name">Map Commune</div>
            <div class="p-faction">Publique · temps réel</div>
        </div>
        <span class="badge badge-blue"><i class="fa-solid fa-circle" style="font-size:6px;"></i> Active</span>
    </div>
    <div class="panel-item active">
        <div class="p-icon" style="background:rgba(139,92,246,.15);color:var(--purple);"><i class="fa-solid fa-users-viewfinder"></i></div>
        <div class="p-info">
            <div class="p-name">Map Gang</div>
            <div class="p-faction">Collaborative · zones + temps réel</div>
        </div>
        <span class="badge badge-purple"><i class="fa-solid fa-circle" style="font-size:6px;"></i> Active</span>
    </div>
</div>

<!-- ══ CARTES DE FACTION ══ -->
<div class="nav-section-label" style="padding-left:0;margin-bottom:10px;">Cartes de faction</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;">
    <?php foreach ($factionMeta as $key => $m):
        $isOn = !empty($activeStates[$key]);
    ?>
    <div class="panel-item map-fac-card <?php echo $isOn ? 'active' : ''; ?>"
         data-faction="<?php echo htmlspecialchars($key); ?>"
         style="cursor:pointer;"
         onclick="openFactionModal('<?php echo htmlspecialchars($key); ?>', '<?php echo htmlspecialchars($m['name'], ENT_QUOTES); ?>')">
        <?php echo map_fac_visual($m); ?>
        <div class="p-info">
            <div class="p-name"><?php echo htmlspecialchars($m['name']); ?></div>
            <div class="p-faction"><?php echo htmlspecialchars($key); ?> · configurer</div>
        </div>
        <label class="toggle" onclick="event.stopPropagation();">
            <input type="checkbox" <?php echo $isOn ? 'checked' : ''; ?>
                   onchange="toggleFaction('<?php echo htmlspecialchars($key); ?>', this.checked, this)">
            <span class="toggle-slider"></span>
        </label>
    </div>
    <?php endforeach; ?>
</div>

<!-- ══ MODAL CONFIGURATION FACTION ══ -->
<div class="modal-overlay" id="factionModal">
    <div class="modal" style="max-width:660px;">
        <div class="modal-header">
            <h3 id="fmTitle"><i class="fa-solid fa-sliders" style="color:var(--green);margin-right:8px;"></i> Configuration</h3>
            <button class="modal-close" onclick="closeModal('factionModal')">&times;</button>
        </div>

        <div class="tabs" data-tab-group="fm">
            <button class="tab-btn active" data-tab="fmPerms" id="fmPermsBtn"><i class="fa-solid fa-user-shield"></i> Permissions</button>
            <button class="tab-btn" data-tab="fmTags"><i class="fa-solid fa-tags"></i> Tags</button>
        </div>

        <!-- Tab Permissions -->
        <div class="tab-pane active" id="tab-fmPerms">
            <p class="form-hint" style="margin-bottom:14px;">
                Choisissez quelles factions ont accès aux cartes de <strong id="fmOwnerLabel"></strong>.
            </p>
            <div class="table-wrapper" style="border:1px solid var(--border);border-radius:12px;">
                <table>
                    <thead>
                        <tr>
                            <th>Faction</th>
                            <th style="text-align:center;">View</th>
                            <th style="text-align:center;">Modifier</th>
                            <th style="text-align:center;">Créer</th>
                            <th style="text-align:center;">Supprimer</th>
                        </tr>
                    </thead>
                    <tbody id="fmPermRows">
                        <tr><td colspan="5" class="text-muted" style="text-align:center;padding:20px;">Chargement…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('factionModal')">Fermer</button>
                <button class="btn btn-primary" id="fmSavePerms" onclick="savePermissions()">
                    <i class="fa-solid fa-floppy-disk"></i> Enregistrer les permissions
                </button>
            </div>
        </div>

        <!-- Tab Tags -->
        <div class="tab-pane" id="tab-fmTags">
            <p class="form-hint" style="margin-bottom:14px;">
                Tags globaux, réutilisables sur toutes les cartes.
            </p>
            <div id="fmTagList" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;min-height:32px;"></div>
            <div style="display:flex;gap:8px;align-items:center;">
                <input class="form-control" id="fmTagName" placeholder="Nouveau tag…" maxlength="80" style="flex:1;"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();createTag();}">
                <input type="color" id="fmTagColor" value="#10b981" class="form-control" style="width:52px;padding:4px;cursor:pointer;">
                <button class="btn btn-success" onclick="createTag()"><i class="fa-solid fa-plus"></i> Ajouter</button>
            </div>
        </div>
    </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
(function () {
    const API  = 'map.php';
    const CSRF = window.__MAP_CSRF;

    function post(action, payload) {
        return fetch(API + '?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(Object.assign({ csrf: CSRF }, payload || {})),
            credentials: 'same-origin'
        }).then(r => r.json());
    }
    function get(action, params) {
        const q = new URLSearchParams(Object.assign({ action: action }, params || {}));
        return fetch(API + '?' + q.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
        }).then(r => r.json());
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    let currentOwner = null;

    /* ── Activation faction ── */
    window.toggleFaction = function (key, active, el) {
        post('toggle_faction', { faction_key: key, active: active ? 1 : 0 }).then(j => {
            if (!j.ok) { toast(j.error || 'Erreur', 'error'); if (el) el.checked = !active; return; }
            const card = document.querySelector('.map-fac-card[data-faction="' + key + '"]');
            if (card) card.classList.toggle('active', active);
            toast(active ? 'Carte activée' : 'Carte désactivée', active ? 'success' : 'info');
        }).catch(() => { toast('Erreur réseau', 'error'); if (el) el.checked = !active; });
    };

    /* ── Modal permissions ── */
    window.openFactionModal = function (key, name) {
        currentOwner = key;
        document.getElementById('fmTitle').innerHTML =
            '<i class="fa-solid fa-sliders" style="color:var(--green);margin-right:8px;"></i> Carte ' + esc(name);
        document.getElementById('fmOwnerLabel').textContent = name;
        document.getElementById('fmPermsBtn').style.display = '';
        // reset to permissions tab
        document.querySelector('[data-tab="fmPerms"]').click();
        document.getElementById('fmPermRows').innerHTML =
            '<tr><td colspan="5" class="text-muted" style="text-align:center;padding:20px;">Chargement…</td></tr>';
        openModal('factionModal');
        get('get_permissions', { owner_faction: key }).then(j => {
            if (!j.ok) { toast(j.error || 'Erreur', 'error'); return; }
            renderPermRows(j.data.grantees || []);
        });
        loadTags();
    };

    function permCheckbox(faction, field, checked) {
        return '<td style="text-align:center;"><input type="checkbox" data-faction="' + faction +
            '" data-field="' + field + '"' + (checked ? ' checked' : '') +
            ' style="width:17px;height:17px;cursor:pointer;accent-color:var(--green);"></td>';
    }
    function renderPermRows(rows) {
        const tb = document.getElementById('fmPermRows');
        if (!rows.length) { tb.innerHTML = '<tr><td colspan="5" class="text-muted" style="text-align:center;padding:20px;">Aucune autre faction.</td></tr>'; return; }
        tb.innerHTML = rows.map(r =>
            '<tr>' +
            '<td><span style="display:inline-flex;align-items:center;gap:8px;"><span style="width:9px;height:9px;border-radius:50%;background:' + esc(r.color) + ';"></span><strong>' + esc(r.name) + '</strong></span></td>' +
            permCheckbox(r.faction, 'view',   r.view) +
            permCheckbox(r.faction, 'edit',   r.edit) +
            permCheckbox(r.faction, 'create', r.create) +
            permCheckbox(r.faction, 'delete', r.delete) +
            '</tr>'
        ).join('');
    }

    window.savePermissions = function () {
        if (!currentOwner) return;
        const map = {};
        document.querySelectorAll('#fmPermRows input[type="checkbox"]').forEach(cb => {
            const f = cb.dataset.faction;
            map[f] = map[f] || { faction: f, view: 0, edit: 0, create: 0, delete: 0 };
            map[f][cb.dataset.field] = cb.checked ? 1 : 0;
        });
        const btn = document.getElementById('fmSavePerms');
        btn.disabled = true;
        post('save_permissions', { owner_faction: currentOwner, perms: Object.values(map) }).then(j => {
            btn.disabled = false;
            if (!j.ok) { toast(j.error || 'Erreur', 'error'); return; }
            toast('Permissions enregistrées', 'success');
        }).catch(() => { btn.disabled = false; toast('Erreur réseau', 'error'); });
    };

    /* ── Tags (globaux) ── */
    window.openTagsModal = function () {
        currentOwner = null;
        document.getElementById('fmTitle').innerHTML =
            '<i class="fa-solid fa-tags" style="color:var(--green);margin-right:8px;"></i> Tags';
        document.getElementById('fmPermsBtn').style.display = 'none';
        document.querySelector('[data-tab="fmTags"]').click();
        openModal('factionModal');
        loadTags();
    };

    function loadTags() {
        get('tag_list').then(j => { if (j.ok) renderTags(j.data.tags || []); });
    }
    function renderTags(tags) {
        const box = document.getElementById('fmTagList');
        if (!tags.length) { box.innerHTML = '<span class="text-muted" style="font-size:.82rem;">Aucun tag.</span>'; return; }
        box.innerHTML = tags.map(t =>
            '<span style="display:inline-flex;align-items:center;gap:7px;background:var(--bg-hover);border:1px solid var(--border);border-radius:20px;padding:5px 6px 5px 12px;font-size:.8rem;">' +
            '<span style="width:9px;height:9px;border-radius:50%;background:' + esc(t.color) + ';"></span>' +
            esc(t.nom) +
            '<button onclick="deleteTag(' + Number(t.id) + ',this)" title="Supprimer" style="border:none;background:none;color:var(--text-muted);cursor:pointer;width:18px;height:18px;border-radius:50%;display:grid;place-items:center;">&times;</button>' +
            '</span>'
        ).join('');
    }
    window.createTag = function () {
        const nom = document.getElementById('fmTagName').value.trim();
        const color = document.getElementById('fmTagColor').value || '#64748b';
        if (!nom) { toast('Nom du tag requis', 'warning'); return; }
        post('tag_create', { nom: nom, color: color }).then(j => {
            if (!j.ok) { toast(j.error || 'Erreur', 'error'); return; }
            document.getElementById('fmTagName').value = '';
            toast('Tag ajouté', 'success');
            loadTags();
        });
    };
    window.deleteTag = function (id, el) {
        if (!confirm('Supprimer ce tag ?')) return;
        post('tag_delete', { id: id }).then(j => {
            if (!j.ok) { toast(j.error || 'Erreur', 'error'); return; }
            toast('Tag supprimé', 'info');
            loadTags();
        });
    };
})();
</script>
JS;
$extraJs = '<script>window.__MAP_CSRF=' . json_encode($csrf) . ';</script>' . $extraJs;

include __DIR__ . '/../includes/footer.php';
