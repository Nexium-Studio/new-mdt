<?php
/**
 * pages/maps/editor.php — Éditeur / visionneuse d'une carte (faction ou Commune/Gang).
 * Outils : pinceau, surligneur, gomme, points (Plan), zones (Gang), zoom/pan, temps réel.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
requireLogin();
require_once __DIR__ . '/includes/map_bootstrap.php';
require_once __DIR__ . '/includes/map_access.php';
require_once __DIR__ . '/includes/map_assets.php';

$db = Database::getInstance()->getConnection();
mdt_maps_bootstrap($db);

$uid         = maps_user_id();
$navFactions = maps_navbar_factions($db, $uid);

/* ─── Résolution de la carte ciblée ─── */
$special = strtolower(trim((string) ($_GET['special'] ?? '')));
if ($special === 'commune' || $special === 'gang') {
    $st = $db->prepare("SELECT * FROM mdt_maps WHERE scope = ? ORDER BY id ASC LIMIT 1");
    $st->execute([$special]);
} else {
    $st = $db->prepare("SELECT * FROM mdt_maps WHERE id = ? AND scope = 'faction' LIMIT 1");
    $st->execute([(int) ($_GET['id'] ?? 0)]);
}
$map = $st->fetch(PDO::FETCH_ASSOC);
if (!$map || !maps_can_view_map($db, $uid, $map)) {
    redirect(SITE_URL . '/pages/maps/index.php');
}

$mapId    = (int) $map['id'];
$scope    = $map['scope'];
$isGang   = ($scope === 'gang');
$canEdit  = maps_can_edit_map($db, $uid, $map);
if (isset($_GET['view'])) {
    $canEdit = false; // "Consulter" force la lecture seule
}

/* ─── Tag associé ─── */
$tag = null;
if (!empty($map['tag_id'])) {
    $ts = $db->prepare("SELECT nom, color FROM mdt_map_tags WHERE id = ?");
    $ts->execute([(int) $map['tag_id']]);
    $tag = $ts->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ─── Éléments existants (injectés pour éviter un flash) ─── */
$elements = [];
$es = $db->prepare("SELECT id, element_type, data FROM mdt_map_elements WHERE map_id = ? ORDER BY id ASC");
$es->execute([$mapId]);
foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $d = json_decode((string) $r['data'], true);
    if (!is_array($d)) {
        $d = [];
    }
    $elements[] = array_merge(['id' => (int) $r['id'], 'type' => $r['element_type']], $d);
}

/* ─── Contexte navbar / shell ─── */
$mapsActiveNav = ($scope === 'faction') ? (string) $map['faction_key'] : $scope;
$mapsPageTitle = $map['title'];
$mapsMainClass = 'is-editor';
$csrf          = mdt_map_csrf();
$displayName   = function_exists('mdt_session_display_name') ? mdt_session_display_name() : 'Agent';

/* Palette prédéfinie (Rouge → Blanc) */
$palette = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899', '#ffffff'];

define('MDT_MAPS_FRONT', 1);
include __DIR__ . '/includes/front_header.php';
?>

<div class="ed-stage" id="edStage">
    <div class="ed-plane" id="edPlane"><img id="edImg" alt="Fond de carte" src=""></div>
    <canvas class="ed-canvas" id="edCanvas"></canvas>
    <div class="ed-overlay" id="edOverlay"></div>
    <div class="ed-presence" id="edPresence"></div>
</div>

<?php if ($canEdit): ?>
<!-- ══ TOOLBAR ══ -->
<div class="ed-toolbar" id="edToolbar">
    <div class="ed-tools">
        <button class="ed-tool active" data-tool="move" title="Déplacer"><i class="fa-solid fa-up-down-left-right"></i></button>
        <button class="ed-tool" data-tool="pen" title="Pinceau"><i class="fa-solid fa-pen"></i></button>
        <button class="ed-tool" data-tool="marker" title="Marqueur / Stabilo"><i class="fa-solid fa-highlighter"></i></button>
        <button class="ed-tool" data-tool="eraser" title="Gomme"><i class="fa-solid fa-eraser"></i></button>
    </div>
    <div class="ed-tools">
        <button class="ed-tool" data-tool="point" title="Plan (marqueur)"><i class="fa-solid fa-location-dot"></i></button>
        <?php if ($isGang): ?>
        <button class="ed-tool" data-tool="zone" title="Zone"><i class="fa-solid fa-draw-polygon"></i></button>
        <?php endif; ?>
    </div>

    <div class="ed-sec-label">Couleur</div>
    <div class="ed-colors" id="edColors">
        <?php foreach ($palette as $i => $c): ?>
        <span class="ed-color <?php echo $i === 0 ? 'active' : ''; ?>" data-color="<?php echo $c; ?>"
              style="background:<?php echo $c; ?>;<?php echo $c === '#ffffff' ? 'border:2px solid #cbd5e1;' : ''; ?>"></span>
        <?php endforeach; ?>
        <label class="ed-color-custom" title="Couleur personnalisée">
            <input type="color" id="edCustomColor" value="#10b981">
        </label>
    </div>

    <div class="ed-sec-label">Taille</div>
    <div class="ed-size">
        <i class="fa-solid fa-circle" style="font-size:5px;color:var(--m-muted-2)"></i>
        <input type="range" id="edSize" min="1" max="48" value="4">
        <i class="fa-solid fa-circle" style="font-size:12px;color:var(--m-muted-2)"></i>
        <span class="ed-size-val" id="edSizeVal">4</span>
    </div>
</div>
<?php endif; ?>

<!-- ══ INFO ══ -->
<div class="ed-info">
    <div class="ed-info-title"><?php echo htmlspecialchars($map['title']); ?></div>
    <?php if ($tag): ?>
    <span class="ed-info-tag" style="color:<?php echo htmlspecialchars($tag['color']); ?>">
        <span style="width:7px;height:7px;border-radius:50%;background:<?php echo htmlspecialchars($tag['color']); ?>"></span>
        <?php echo htmlspecialchars($tag['nom']); ?>
    </span>
    <?php elseif ($isGang): ?>
    <span class="ed-info-tag" style="color:#a78bfa">Collaboratif · Zones</span>
    <?php elseif ($scope === 'commune'): ?>
    <span class="ed-info-tag" style="color:#93c5fd">Public · Temps réel</span>
    <?php endif; ?>
    <?php if (!$canEdit): ?>
    <span class="ed-info-tag" style="color:#fbbf24"><i class="fa-solid fa-eye"></i> Lecture seule</span>
    <?php endif; ?>
</div>

<!-- ══ ZOOM ══ -->
<div class="ed-zoom">
    <button id="edZoomIn" title="Zoom avant"><i class="fa-solid fa-plus"></i></button>
    <button id="edZoomOut" title="Zoom arrière"><i class="fa-solid fa-minus"></i></button>
    <button id="edZoomFit" title="Recentrer"><i class="fa-solid fa-expand"></i></button>
</div>

<!-- ══ HINT ══ -->
<div class="ed-hint">
    <span><i class="fa-solid fa-computer-mouse"></i> Clic droit : déplacer · Molette : zoom<?php echo $isGang ? ' · Zone : cliquez plusieurs points puis rejoignez le 1er' : ''; ?></span>
    <?php if ($canEdit): ?>
    <span class="ed-save-state" id="edSaveState"><span class="dot"></span> Sauvegardé</span>
    <?php endif; ?>
</div>

<!-- ══ MODAL POINT ══ -->
<div class="m-modal-overlay" id="pointModal">
    <div class="m-modal">
        <h3 id="pmTitle">Nouveau point</h3>
        <div class="mfield">
            <label class="mlabel" for="pmName">Titre</label>
            <input class="minput" id="pmName" maxlength="120" placeholder="Ex. Point de RDV" autocomplete="off">
        </div>
        <div class="mfield">
            <div class="mlabel">Couleur</div>
            <div class="ed-colors" id="pmColors"></div>
        </div>
        <div class="m-modal-foot">
            <div class="del-wrap"><button class="mbtn mbtn-danger" id="pmDelete" style="display:none;"><i class="fa-solid fa-trash"></i> Supprimer</button></div>
            <button class="mbtn mbtn-ghost" onclick="mapsCloseModal('pointModal')">Annuler</button>
            <button class="mbtn mbtn-primary" id="pmSave"><i class="fa-solid fa-check"></i> Enregistrer</button>
        </div>
    </div>
</div>

<!-- ══ MODAL ZONE ══ -->
<div class="m-modal-overlay" id="zoneModal">
    <div class="m-modal">
        <h3 id="zmTitle">Nouvelle zone</h3>
        <div class="mfield">
            <label class="mlabel" for="zmName">Titre</label>
            <input class="minput" id="zmName" maxlength="120" placeholder="Ex. Territoire Ballas" autocomplete="off">
        </div>
        <div class="mfield">
            <div class="mlabel">Couleur</div>
            <div class="ed-colors" id="zmColors"></div>
        </div>
        <div class="m-modal-foot">
            <div class="del-wrap"><button class="mbtn mbtn-danger" id="zmDelete" style="display:none;"><i class="fa-solid fa-trash"></i> Supprimer</button></div>
            <button class="mbtn mbtn-ghost" onclick="mapsCloseModal('zoneModal')">Annuler</button>
            <button class="mbtn mbtn-primary" id="zmSave"><i class="fa-solid fa-check"></i> Enregistrer</button>
        </div>
    </div>
</div>

<?php
$cfgJson = json_encode([
    'apiBase'           => SITE_URL . '/pages/maps/api.php',
    'target'            => ($scope === 'faction') ? ['id' => $mapId] : ['special' => $scope],
    'background'        => $map['background'],
    'backgroundSources' => maps_background_sources(),
    'elements'          => $elements,
    'revision'          => (int) $map['revision'],
    'csrf'              => $csrf,
    'canEdit'           => $canEdit,
    'isGang'            => $isGang,
    'displayName'       => $displayName,
], JSON_UNESCAPED_UNICODE);
$paletteJson = json_encode($palette);
$engineV = @filemtime(dirname(__DIR__, 2) . '/assets/js/maps/map-engine.js') ?: '1';
?>
<script>window.MDT_MAP_CONFIG = <?php echo $cfgJson; ?>; window.MDT_PALETTE = <?php echo $paletteJson; ?>;</script>
<?php $mapsExtraJs = '<script src="' . SITE_URL . '/assets/js/maps/map-engine.js?v=' . $engineV . '"></script>' . <<<'JS'
<script>
(function () {
    const CFG = window.MDT_MAP_CONFIG;
    const PALETTE = window.MDT_PALETTE || ['#ef4444'];

    function $(id){ return document.getElementById(id); }
    function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
    window.mapsCloseModal = function(id){ $(id).classList.remove('open'); };

    /* ── État des modales ── */
    let pointCtx = null;   // {mode:'create', nx, ny} | {mode:'edit', el}
    let zoneCtx  = null;   // {mode:'create', points} | {mode:'edit', el}

    /* ── Instanciation moteur ── */
    const engine = new MdtMapEngine(Object.assign({}, CFG, {
        stage:   $('edStage'),
        plane:   $('edPlane'),
        img:     $('edImg'),
        canvas:  $('edCanvas'),
        overlay: $('edOverlay'),
        presence: $('edPresence'),
        onPointCreate: function (nx, ny) { openPointModal({ mode:'create', nx:nx, ny:ny }); },
        onPointEdit:   function (el)     { if (el) openPointModal({ mode:'edit', el:el }); },
        onZoneCreate:  function (points) { openZoneModal({ mode:'create', points:points }); },
        onZoneEdit:    function (el)     { if (el) openZoneModal({ mode:'edit', el:el }); },
        onSaveState:   function (st)     { updateSaveState(st); }
    }));
    engine.init();

    /* ── Toolbar : outils ── */
    document.querySelectorAll('.ed-tool').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.ed-tool').forEach(x => x.classList.remove('active'));
            b.classList.add('active');
            engine.setTool(b.dataset.tool);
        });
    });

    /* ── Toolbar : couleurs ── */
    const colorEls = document.querySelectorAll('#edColors .ed-color');
    colorEls.forEach(function (c) {
        c.addEventListener('click', function () {
            colorEls.forEach(x => x.classList.remove('active'));
            c.classList.add('active');
            engine.setColor(c.dataset.color);
        });
    });
    const custom = $('edCustomColor');
    if (custom) custom.addEventListener('input', function () {
        colorEls.forEach(x => x.classList.remove('active'));
        engine.setColor(custom.value);
    });

    /* ── Toolbar : taille ── */
    const sizeEl = $('edSize'), sizeVal = $('edSizeVal');
    if (sizeEl) sizeEl.addEventListener('input', function () {
        sizeVal.textContent = sizeEl.value;
        engine.setSize(sizeEl.value);
    });

    /* ── Zoom ── */
    $('edZoomIn').addEventListener('click', function () { engine.zoomIn(); });
    $('edZoomOut').addEventListener('click', function () { engine.zoomOut(); });
    $('edZoomFit').addEventListener('click', function () { engine.resetView(); });

    /* ── Indicateur de sauvegarde ── */
    let saveTimer = null;
    function updateSaveState(st) {
        const el = $('edSaveState'); if (!el) return;
        if (st === 'saving') { el.classList.add('saving'); el.innerHTML = '<span class="dot"></span> Sauvegarde…'; }
        else if (st === 'error') { el.classList.remove('saving'); el.innerHTML = '<span class="dot" style="background:#ef4444"></span> Erreur'; }
        else { el.classList.remove('saving'); el.innerHTML = '<span class="dot"></span> Sauvegardé'; }
    }

    /* ── Palette dans les modales ── */
    function buildPalette(container, onPick) {
        container.innerHTML = '';
        PALETTE.forEach(function (c, i) {
            const s = document.createElement('span');
            s.className = 'ed-color' + (i === 0 ? ' active' : '');
            s.style.background = c;
            if (c === '#ffffff') s.style.border = '2px solid #cbd5e1';
            s.dataset.color = c;
            s.addEventListener('click', function () {
                container.querySelectorAll('.ed-color').forEach(x => x.classList.remove('active'));
                s.classList.add('active');
                onPick(c);
            });
            container.appendChild(s);
        });
    }
    function setPaletteActive(container, color) {
        let found = false;
        container.querySelectorAll('.ed-color').forEach(function (x) {
            const on = x.dataset.color.toLowerCase() === String(color).toLowerCase();
            x.classList.toggle('active', on); if (on) found = true;
        });
        if (!found) { const first = container.querySelector('.ed-color'); if (first) first.classList.add('active'); }
    }

    let pmColor = PALETTE[0], zmColor = PALETTE[0];
    buildPalette($('pmColors'), function (c) { pmColor = c; });
    buildPalette($('zmColors'), function (c) { zmColor = c; });

    /* ── Modale POINT ── */
    function openPointModal(ctx) {
        pointCtx = ctx;
        $('pmTitle').textContent = ctx.mode === 'edit' ? 'Modifier le point' : 'Nouveau point';
        $('pmName').value = ctx.mode === 'edit' ? (ctx.el.title || '') : '';
        pmColor = ctx.mode === 'edit' ? (ctx.el.color || PALETTE[0]) : PALETTE[0];
        setPaletteActive($('pmColors'), pmColor);
        $('pmDelete').style.display = ctx.mode === 'edit' ? '' : 'none';
        $('pointModal').classList.add('open');
        setTimeout(function () { $('pmName').focus(); }, 60);
    }
    $('pmSave').addEventListener('click', function () {
        if (!pointCtx) return;
        const title = $('pmName').value.trim();
        if (pointCtx.mode === 'create') engine.createPoint(pointCtx.nx, pointCtx.ny, title, pmColor);
        else engine.updatePoint(pointCtx.el.id, title, pmColor);
        mapsCloseModal('pointModal');
    });
    $('pmDelete').addEventListener('click', function () {
        if (pointCtx && pointCtx.mode === 'edit') { engine.deletePoint(pointCtx.el.id); mapsCloseModal('pointModal'); }
    });

    /* ── Modale ZONE ── */
    function openZoneModal(ctx) {
        zoneCtx = ctx;
        $('zmTitle').textContent = ctx.mode === 'edit' ? 'Modifier la zone' : 'Nouvelle zone';
        $('zmName').value = ctx.mode === 'edit' ? (ctx.el.title || '') : '';
        zmColor = ctx.mode === 'edit' ? (ctx.el.color || PALETTE[0]) : '#94a3b8';
        setPaletteActive($('zmColors'), zmColor);
        $('zmDelete').style.display = ctx.mode === 'edit' ? '' : 'none';
        $('zoneModal').classList.add('open');
        setTimeout(function () { $('zmName').focus(); }, 60);
    }
    $('zmSave').addEventListener('click', function () {
        if (!zoneCtx) return;
        const title = $('zmName').value.trim();
        if (zoneCtx.mode === 'create') engine.createZone(zoneCtx.points, title, zmColor);
        else engine.updateZone(zoneCtx.el.id, title, zmColor);
        mapsCloseModal('zoneModal');
    });
    $('zmDelete').addEventListener('click', function () {
        if (zoneCtx && zoneCtx.mode === 'edit') { engine.deleteZone(zoneCtx.el.id); mapsCloseModal('zoneModal'); }
    });

    /* Fermer une modale en cliquant le fond */
    document.querySelectorAll('.m-modal-overlay').forEach(function (o) {
        o.addEventListener('click', function (e) { if (e.target === o) o.classList.remove('open'); });
    });
})();
</script>
JS;
include __DIR__ . '/includes/front_footer.php';
