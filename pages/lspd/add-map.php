<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction'] ?? '', ['LSPD', 'LSSD', 'DOJ'], true) &&
    !hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Nouveau plan tactique';
require_once __DIR__ . '/includes/map_api.php';
require_once __DIR__ . '/includes/lspd_map_assets.php';

$csrf         = lspd_map_ensure_csrf();
$mapLayerUrls = lspd_map_layer_urls();
$mapThumbUrls = lspd_map_thumb_urls();

/* ─── Config pré-encodée pour injection inline ──────── */
$cfgPayload = [
    'mapSources'  => $mapLayerUrls,
    'map_layer'   => 'atlas',
    'strokes'     => [],
    'revision'    => 0,
    'viewport'    => null,
    'ajaxBase'    => SITE_URL . '/pages/lspd/ajax/',
    'csrf'        => $csrf,
    'mapId'       => null,  // pas de mapId : pas de collab
    'displayName' => (string) ($_SESSION['user_username'] ?? 'Agent'),
];
$cfgJson = lspd_map_json_for_script($cfgPayload, 'null');

include __DIR__ . '/includes/header.php';
?>

<?php /* ═════════ Config injectée en priorité ═════════ */ ?>
<script>
(function () {
  try {
    window.LSPD_MAP_CONFIG = <?php echo $cfgJson; ?>;
    if (window.console) console.log('[LSPD Map add] Config injectée', window.LSPD_MAP_CONFIG);
  } catch (e) {
    if (window.console) console.error('[LSPD Map add] Erreur config:', e);
  }
})();
</script>

<style>
.add-map-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}

/* Layout 2 colonnes */
.add-map-body{display:grid;grid-template-columns:300px 1fr;gap:1.25rem;align-items:start}
@media(max-width:860px){.add-map-body{grid-template-columns:1fr}}
.add-map-form-card{position:sticky;top:1rem}

/* Sélecteur de fond */
.layer-cards{display:flex;flex-direction:column;gap:.5rem;margin-bottom:1rem}
.layer-card{display:flex;align-items:center;gap:.65rem;padding:.6rem .8rem;border:2px solid var(--bdr);border-radius:10px;cursor:pointer;transition:border-color .15s,background .15s}
.layer-card:hover{border-color:var(--bdr-h);background:rgba(255,255,255,.03)}
.layer-card.selected{border-color:var(--blue);background:rgba(37,99,235,.08)}
.layer-card input[type=radio]{accent-color:var(--blue);flex-shrink:0}
.layer-card-thumb{width:56px;height:38px;border-radius:6px;object-fit:cover;flex-shrink:0;border:1px solid var(--bdr);background:#0a1020}
.layer-card-label{font-size:.82rem;font-weight:600;color:var(--txt)}
.layer-card-sub{font-size:.7rem;color:var(--txt-s);margin-top:.1rem}

/* Aperçu — hauteur fixe, pas de calc() qui s'effondre */
.map-preview-shell{
    position:relative;
    border-radius:12px;overflow:hidden;
    border:1px solid var(--bdr);background:#020508;
    height:75vh;min-height:500px;max-height:900px;
}

/* Viewport — IDs alignés avec la nouvelle API lspd-map.js v8 */
#am-vp{
    position:absolute;inset:0;
    overflow:hidden;touch-action:none;
    user-select:none;-webkit-user-select:none;
}

#am-plane{
    position:absolute;left:0;top:0;
    transform-origin:0 0;will-change:transform;
    background:#0a1020;width:1px;height:1px;
}
#am-img{
    display:block;width:100%;height:100%;
    object-fit:fill;pointer-events:none;
    user-select:none;-webkit-user-drag:none;
}

/* Canvas HORS du plan */
#am-cv{
    position:absolute;inset:0;
    width:100%;height:100%;
    pointer-events:none;z-index:3;
}

/* Zoom */
.map-preview-zoom{position:absolute;right:12px;bottom:12px;z-index:20;display:flex;flex-direction:column;gap:6px}
.map-preview-zoom button{
    width:40px;height:40px;border-radius:10px;
    border:1px solid var(--bdr);background:rgba(3,8,15,.92);
    color:var(--txt);cursor:pointer;font-size:1.1rem;
    display:flex;align-items:center;justify-content:center;
}
.map-preview-zoom button:hover{background:rgba(37,99,235,.2)}

/* Aide */
.map-preview-help{
    position:absolute;right:12px;top:12px;z-index:20;
    font-size:.67rem;color:var(--txt-s);
    background:rgba(3,8,15,.88);border:1px solid var(--bdr);
    padding:.4rem .6rem;border-radius:8px;line-height:1.5;max-width:260px;
}

/* Spinner */
.map-layer-loading{
    display:none;position:absolute;inset:0;
    align-items:center;justify-content:center;
    background:rgba(2,5,8,.55);z-index:30;
    font-size:.85rem;color:var(--txt-s);
}
.map-layer-loading.on{display:flex}
</style>

<!-- En-tête -->
<div class="add-map-hd">
    <div>
        <div class="ls-topbar-title" style="position:static;padding:0;border:none">
            <i class="fas fa-plus"></i> Nouveau plan tactique
        </div>
        <div class="ls-topbar-sub" style="margin-top:.35rem">
            Choisissez un fond, nommez le plan, puis créez.
        </div>
    </div>
    <a href="map.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Liste des plans</a>
</div>

<div class="add-map-body">

    <!-- Formulaire -->
    <div class="card add-map-form-card" style="padding:1.25rem">

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Titre <span style="color:#ef4444">*</span></label>
            <input type="text" id="mapTitle" class="form-input"
                   placeholder="Ex : Opération Bravo…"
                   maxlength="255" autocomplete="off">
        </div>

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Nature</label>
            <select id="mapNature" class="form-select">
                <option value="operation">Opération</option>
                <option value="briefing">Briefing</option>
                <option value="surveillance">Surveillance</option>
                <option value="patrouille">Patrouille</option>
                <option value="enquete">Enquête</option>
                <option value="autre" selected>Autre</option>
            </select>
        </div>

        <div class="form-group" style="margin-bottom:1.25rem">
            <label class="form-label">Fond de carte <span style="color:#ef4444">*</span></label>
            <div class="layer-cards">

                <label class="layer-card selected" id="card-atlas">
                    <input type="radio" name="mapLayer" value="atlas" checked>
                    <img class="layer-card-thumb"
                         src="<?php echo htmlspecialchars($mapThumbUrls['atlas'], ENT_QUOTES, 'UTF-8'); ?>"
                         alt="Atlas" loading="eager">
                    <div>
                        <div class="layer-card-label">Atlas</div>
                        <div class="layer-card-sub">Vue artistique colorée</div>
                    </div>
                </label>

                <label class="layer-card" id="card-road">
                    <input type="radio" name="mapLayer" value="road">
                    <img class="layer-card-thumb"
                         src="<?php echo htmlspecialchars($mapThumbUrls['road'], ENT_QUOTES, 'UTF-8'); ?>"
                         alt="Routes" loading="lazy">
                    <div>
                        <div class="layer-card-label">Routes</div>
                        <div class="layer-card-sub">Réseau routier détaillé</div>
                    </div>
                </label>

                <label class="layer-card" id="card-satellite">
                    <input type="radio" name="mapLayer" value="satellite">
                    <img class="layer-card-thumb"
                         src="<?php echo htmlspecialchars($mapThumbUrls['satellite'], ENT_QUOTES, 'UTF-8'); ?>"
                         alt="Satellite" loading="lazy">
                    <div>
                        <div class="layer-card-label">Satellite</div>
                        <div class="layer-card-sub">Vue aérienne réaliste</div>
                    </div>
                </label>

            </div>
        </div>

        <button type="button" id="btnSaveMap" class="btn btn-primary" style="width:100%">
            <i class="fas fa-save"></i> Créer le plan
        </button>
        <div id="mapSaveError" style="color:#ef4444;font-size:.78rem;margin-top:.6rem;display:none"></div>
    </div>

    <!-- Aperçu -->
    <div class="map-preview-shell">
        <div id="am-vp">

            <!-- Plan : image seule -->
            <div id="am-plane">
                <img id="am-img"
                     src="<?php echo htmlspecialchars($mapLayerUrls['atlas'], ENT_QUOTES, 'UTF-8'); ?>"
                     alt="Aperçu carte"
                     decoding="sync"
                     fetchpriority="high"
                     loading="eager">
            </div>

            <!-- Canvas hors du plan -->
            <canvas id="am-cv"></canvas>

        </div>

        <div class="map-layer-loading" id="mapLayerLoading">
            <i class="fas fa-spinner fa-spin" style="margin-right:.5rem"></i> Chargement…
        </div>

        <div class="map-preview-help">
            <strong>Molette</strong> : zoom &nbsp;|&nbsp; <strong>Clic droit</strong> : déplacer
        </div>

        <div class="map-preview-zoom">
            <button type="button" id="amZi">+</button>
            <button type="button" id="amZo">−</button>
        </div>
    </div>

</div>

<script src="<?php echo htmlspecialchars(SITE_URL.'/assets/js/lspd-map.js'); ?>"></script>
<script>
(function () {
  'use strict';

  var AJAX        = <?php echo lspd_map_json_for_script(SITE_URL.'/pages/lspd/ajax/', '""'); ?>;
  var CSRF        = <?php echo lspd_map_json_for_script($csrf, '""'); ?>;
  var SITE_URL_JS = <?php echo lspd_map_json_for_script(SITE_URL, '""'); ?>;

  var editor = null;

  function boot() {
    if (typeof LspdMapEditor !== 'function') {
      console.error('[LSPD Map add] LspdMapEditor introuvable — vérifiez /assets/js/lspd-map.js');
      return;
    }

    try {
      /* Nouvelle API v8 : vpId, planeId, imgId, cvId, zinId, zoutId */
      editor = new LspdMapEditor({
        vpId:    'am-vp',
        planeId: 'am-plane',
        imgId:   'am-img',
        cvId:    'am-cv',
        zinId:   'amZi',
        zoutId:  'amZo',
        collab:  false,  // pas de collab avant création
      });
      editor.init();
      window._addEditor = editor;
      console.log('[LSPD Map add] Preview editor ready');
    } catch (e) {
      console.error('[LSPD Map add] Erreur init:', e);
    }
  }

  /* Sélection de fond */
  document.querySelectorAll('input[name="mapLayer"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      document.querySelectorAll('.layer-card').forEach(function (c) { c.classList.remove('selected'); });
      var card = document.getElementById('card-' + radio.value);
      if (card) card.classList.add('selected');

      if (!editor) return;

      var loadEl = document.getElementById('mapLayerLoading');
      var img    = document.getElementById('am-img');
      if (loadEl) loadEl.classList.add('on');
      if (img) {
        var done = false;
        var finish = function () { if (!done) { done = true; if (loadEl) loadEl.classList.remove('on'); } };
        img.addEventListener('load',  finish, { once: true });
        img.addEventListener('error', finish, { once: true });
        setTimeout(finish, 8000);
      }
      editor.setLayerMode(radio.value);
      if (window.LSPD_MAP_CONFIG) window.LSPD_MAP_CONFIG.map_layer = radio.value;
    });
  });

  /* Clic sur la card entière */
  document.querySelectorAll('.layer-card').forEach(function (card) {
    card.addEventListener('click', function () {
      var radio = card.querySelector('input[type=radio]');
      if (radio && !radio.checked) {
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
  });

  /* Sauvegarde */
  var btnSave = document.getElementById('btnSaveMap');
  var errEl   = document.getElementById('mapSaveError');

  if (btnSave) {
    btnSave.addEventListener('click', function () {
      if (errEl) errEl.style.display = 'none';

      var title    = ((document.getElementById('mapTitle')  || {}).value || '').trim();
      var nature   = (document.getElementById('mapNature') || {}).value || 'autre';
      var layerEl  = document.querySelector('input[name="mapLayer"]:checked');
      var mapLayer = layerEl ? layerEl.value : 'atlas';

      if (title.length < 2) {
        if (errEl) { errEl.textContent = 'Titre trop court (min 2 caractères).'; errEl.style.display = 'block'; }
        return;
      }
      if (title.length > 255) {
        if (errEl) { errEl.textContent = 'Titre trop long.'; errEl.style.display = 'block'; }
        return;
      }

      btnSave.disabled = true;
      btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Création…';

      fetch(AJAX + 'map_save.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          title:     title,
          nature:    nature,
          map_layer: mapLayer,
          strokes:   [],
          viewport:  editor ? editor.getViewport() : null,
          csrf:      CSRF,
        }),
      })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Erreur serveur');
        window.location.href = SITE_URL_JS + '/pages/lspd/map-view.php?id=' + encodeURIComponent(j.id);
      })
      .catch(function (err) {
        if (errEl) { errEl.textContent = err.message || 'Impossible de créer le plan.'; errEl.style.display = 'block'; }
        btnSave.disabled = false;
        btnSave.innerHTML = '<i class="fas fa-save"></i> Créer le plan';
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setTimeout(boot, 0); });
  } else {
    setTimeout(boot, 0);
  }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>