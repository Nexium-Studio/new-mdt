<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction'] ?? '', ['LSPD', 'LSSD', 'DOJ'], true) &&
    !hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) redirect(SITE_URL . '/pages/lssd/map.php');

$db  = Database::getInstance()->getConnection();
$map = null;
try {
    $st = $db->prepare('SELECT id, title, nature, map_layer, strokes_json, viewport_json, revision, author_id FROM lssd_maps WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $map = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $map = null; }
if (!$map) redirect(SITE_URL . '/pages/lssd/map.php');

require_once __DIR__ . '/includes/map_api.php';
require_once __DIR__ . '/includes/lssd_map_assets.php';

if (!lssd_map_user_can_edit((int)$map['author_id'])) {
    redirect(SITE_URL . '/pages/lssd/map.php');
}

$pageTitle = 'Modifier : ' . $map['title'];

$csrf         = lssd_map_ensure_csrf();
$mapLayerUrls = lssd_map_layer_urls();
$mapThumbUrls = lssd_map_thumb_urls();

$currentLayer = in_array((string)($map['map_layer']??''), ['atlas','road','satellite'], true)
    ? $map['map_layer'] : 'atlas';
$mapViewImgSrc = $mapLayerUrls[$currentLayer] ?? $mapLayerUrls['atlas'];

$strokes = json_decode((string)$map['strokes_json'], true);
if (!is_array($strokes)) $strokes = [];
$viewport = json_decode((string)($map['viewport_json'] ?: '{}'), true);
if (!is_array($viewport)) $viewport = null;
else $viewport = lssd_map_viewport_for_engine($viewport);

// Valeurs pré-calculées pour le rendu (header.php réassigne $row en interne).
$mapTitle       = (string) ($map['title']   ?? '');
$mapNature      = (string) ($map['nature']  ?? 'autre');
$mapRevision    = (int)    ($map['revision'] ?? 0);

include __DIR__ . '/includes/header.php';
?>
<link rel="preload" as="image" href="<?php echo htmlspecialchars($mapViewImgSrc, ENT_QUOTES, 'UTF-8'); ?>">

<style>
.edit-map-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}

.edit-map-body{
  display:grid;
  grid-template-columns:300px 1fr;
  gap:1.25rem;
  align-items:start;
}
@media(max-width:860px){.edit-map-body{grid-template-columns:1fr}}

.edit-map-form-card{position:sticky;top:1rem}

/* Sélecteur de fond */
.layer-cards{display:flex;flex-direction:column;gap:.5rem;margin-bottom:1rem}
.layer-card{
  display:flex;align-items:center;gap:.65rem;
  padding:.6rem .8rem;
  border:2px solid var(--bdr);border-radius:10px;
  cursor:pointer;transition:border-color .15s,background .15s;
}
.layer-card:hover{border-color:var(--bdr-h);background:rgba(255,255,255,.03)}
.layer-card.selected{border-color:var(--blue);background:rgba(16,185,129,.08)}
.layer-card input[type=radio]{accent-color:var(--blue);flex-shrink:0}
.layer-card-thumb{width:56px;height:38px;border-radius:6px;object-fit:cover;flex-shrink:0;border:1px solid var(--bdr);background:#0a1020}
.layer-card-label{font-size:.82rem;font-weight:600;color:var(--txt)}
.layer-card-sub{font-size:.7rem;color:var(--txt-s);margin-top:.1rem}

/* Carte */
.map-edit-shell{
  position:relative;border-radius:12px;overflow:hidden;
  border:1px solid var(--bdr);background:#020508;
  height:75vh;min-height:500px;max-height:900px;
}
#edit-map-viewport{position:absolute;inset:0;overflow:hidden;touch-action:none;user-select:none;-webkit-user-select:none}
#edit-map-plane{position:absolute;left:0;top:0;transform-origin:0 0;will-change:transform;background:#0a1020;width:1px;height:1px}
#edit-map-img{display:block;width:100%;height:100%;object-fit:fill;pointer-events:none;user-select:none;-webkit-user-drag:none}
#edit-map-canvas{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:3}

/* Toolbar */
.lssd-map-toolbar{
  position:absolute;left:12px;top:12px;z-index:500;
  display:flex;flex-direction:column;gap:.45rem;padding:.55rem;
  background:rgba(3,8,15,.93);border:1px solid var(--bdr);border-radius:10px;max-width:210px;
}
.lssd-map-toolbar label{font-size:.58rem;text-transform:uppercase;color:var(--txt-s);display:block;margin-bottom:.15rem}
.lssd-map-colors{display:flex;flex-wrap:wrap;gap:4px}
.lssd-map-colors button{width:22px;height:22px;border-radius:50%;border:2px solid transparent;cursor:pointer;padding:0}
.lssd-map-colors button.on{box-shadow:0 0 0 2px var(--blue-l)}

.lssd-map-zoom{position:absolute;right:12px;bottom:12px;z-index:500;display:flex;flex-direction:column;gap:6px}
.lssd-map-zoom button{width:40px;height:40px;border-radius:10px;border:1px solid var(--bdr);background:rgba(3,8,15,.94);color:var(--txt);cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center}
.lssd-map-zoom button:hover{background:rgba(16,185,129,.2)}

.lssd-map-help{position:absolute;right:12px;top:12px;z-index:500;max-width:280px;font-size:.67rem;color:var(--txt-s);background:rgba(3,8,15,.88);border:1px solid var(--bdr);padding:.45rem .6rem;border-radius:8px;line-height:1.5}

.lssd-brush-toggle{width:100%;justify-content:center;font-size:.72rem;padding:.45rem .6rem;border-radius:8px;border:1px solid var(--bdr);background:rgba(15,23,42,.9);color:var(--txt);cursor:pointer;display:inline-flex;align-items:center;gap:.35rem}
.lssd-brush-toggle:hover{border-color:var(--bdr-h)}
.lssd-brush-toggle.is-on{background:rgba(34,197,94,.2);border-color:rgba(34,197,94,.45);color:#86efac}

/* Spinner */
.map-layer-loading{display:none;position:absolute;inset:0;align-items:center;justify-content:center;background:rgba(2,5,8,.55);z-index:30;font-size:.85rem;color:var(--txt-s)}
.map-layer-loading.on{display:flex}

/* Danger zone */
.danger-zone{margin-top:1rem;padding:.75rem;border-radius:8px;border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.06)}
</style>

<!-- En-tête -->
<div class="edit-map-hd">
    <div>
        <div class="ls-topbar-title" style="position:static;padding:0;border:none">
            <i class="fas fa-pen-to-square"></i> Modifier le plan
        </div>
        <div class="ls-topbar-sub" style="margin-top:.35rem">
            Modifiez les métadonnées et les tracés, puis sauvegardez.
        </div>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="map-view.php?id=<?php echo $id;?>" class="btn btn-secondary btn-sm">
            <i class="fas fa-eye"></i> Voir
        </a>
        <a href="map.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Liste</a>
    </div>
</div>

<div class="edit-map-body">

    <!-- ── Formulaire ── -->
    <div class="card edit-map-form-card" style="padding:1.25rem">

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Titre <span style="color:#ef4444">*</span></label>
            <input type="text" id="editMapTitle" class="form-input"
                   value="<?php echo htmlspecialchars($mapTitle, ENT_QUOTES, 'UTF-8'); ?>"
                   maxlength="255" autocomplete="off">
        </div>

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Nature</label>
            <select id="editMapNature" class="form-select">
                <?php
                $natures = ['operation'=>'Opération','briefing'=>'Briefing',
                            'surveillance'=>'Surveillance','patrouille'=>'Patrouille',
                            'enquete'=>'Enquête','autre'=>'Autre'];
                foreach ($natures as $val => $label):
                    $sel = ($mapNature === $val) ? ' selected' : '';
                ?>
                <option value="<?php echo $val;?>"<?php echo $sel;?>><?php echo $label;?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom:1.25rem">
            <label class="form-label">Fond de carte</label>
            <div class="layer-cards">

                <label class="layer-card <?php echo $currentLayer==='atlas'?'selected':'';?>" id="card-atlas">
                    <input type="radio" name="editMapLayer" value="atlas" <?php echo $currentLayer==='atlas'?'checked':'';?>>
                    <img class="layer-card-thumb" src="<?php echo htmlspecialchars($mapThumbUrls['atlas'],ENT_QUOTES,'UTF-8');?>" alt="Atlas" loading="eager">
                    <div><div class="layer-card-label">Atlas</div><div class="layer-card-sub">Vue artistique colorée</div></div>
                </label>

                <label class="layer-card <?php echo $currentLayer==='road'?'selected':'';?>" id="card-road">
                    <input type="radio" name="editMapLayer" value="road" <?php echo $currentLayer==='road'?'checked':'';?>>
                    <img class="layer-card-thumb" src="<?php echo htmlspecialchars($mapThumbUrls['road'],ENT_QUOTES,'UTF-8');?>" alt="Routes" loading="lazy">
                    <div><div class="layer-card-label">Routes</div><div class="layer-card-sub">Réseau routier détaillé</div></div>
                </label>

                <label class="layer-card <?php echo $currentLayer==='satellite'?'selected':'';?>" id="card-satellite">
                    <input type="radio" name="editMapLayer" value="satellite" <?php echo $currentLayer==='satellite'?'checked':'';?>>
                    <img class="layer-card-thumb" src="<?php echo htmlspecialchars($mapThumbUrls['satellite'],ENT_QUOTES,'UTF-8');?>" alt="Satellite" loading="lazy">
                    <div><div class="layer-card-label">Satellite</div><div class="layer-card-sub">Vue aérienne réaliste</div></div>
                </label>

            </div>
        </div>

        <button type="button" id="btnSaveEdit" class="btn btn-primary" style="width:100%">
            <i class="fas fa-save"></i> Sauvegarder
        </button>
        <div id="editSaveError" style="color:#ef4444;font-size:.78rem;margin-top:.6rem;display:none"></div>
        <div id="editSaveOk" style="color:#22c55e;font-size:.78rem;margin-top:.6rem;display:none">
            <i class="fas fa-check"></i> Plan sauvegardé.
        </div>

        <div class="danger-zone" style="margin-top:1.25rem">
            <div style="font-size:.75rem;color:#fca5a5;font-weight:600;margin-bottom:.5rem">
                <i class="fas fa-eraser"></i> Effacer tous les tracés
            </div>
            <button type="button" id="btnClearStrokes" class="btn btn-ghost btn-sm" style="width:100%;border-color:rgba(239,68,68,.4);color:#fca5a5">
                Supprimer les tracés
            </button>
        </div>
    </div>

    <!-- ── Carte avec tracés existants ── -->
    <div class="map-edit-shell">
        <div id="edit-map-viewport">

            <!-- Plan : image seule -->
            <div id="edit-map-plane">
                <img id="edit-map-img"
                     src="<?php echo htmlspecialchars($mapViewImgSrc, ENT_QUOTES, 'UTF-8'); ?>"
                     alt="Carte"
                     decoding="sync"
                     fetchpriority="high"
                     loading="eager">
            </div>

            <!-- Canvas hors du plan -->
            <canvas id="edit-map-canvas"></canvas>

        </div>

        <div class="map-layer-loading" id="mapLayerLoading">
            <i class="fas fa-spinner fa-spin" style="margin-right:.5rem"></i> Chargement…
        </div>

        <div class="lssd-map-help">
            <strong>Molette</strong> : zoom &nbsp;|&nbsp;
            <strong>Clic droit</strong> : déplacer &nbsp;|&nbsp;
            <strong>Pinceau ON</strong> : dessiner
        </div>

        <div class="lssd-map-toolbar">
            <button type="button" class="lssd-brush-toggle" id="editBrushToggle">
                <i class="fas fa-hand"></i> Pinceau : OFF
            </button>
            <div>
                <label>Outil</label>
                <select id="editTool" class="form-select" style="font-size:.78rem">
                    <option value="pen">Stylo</option>
                    <option value="eraser">Gomme</option>
                </select>
            </div>
            <div>
                <label>Couleur</label>
                <div class="lssd-map-colors" id="editColors"></div>
            </div>
            <div>
                <label>Épaisseur</label>
                <input type="range" id="editWidth" min="1" max="24" value="3" style="width:100%">
            </div>
        </div>

        <div class="lssd-map-zoom">
            <button type="button" id="editZoomIn">+</button>
            <button type="button" id="editZoomOut">−</button>
        </div>
    </div>

</div>

<script src="<?php echo htmlspecialchars(SITE_URL.'/assets/js/lssd-map.js'); ?>"></script>
<script>
(function(){
  'use strict';
  var MAP_ID=<?php echo $id;?>;
  var MAP_SOURCES=<?php echo lssd_map_json_for_script($mapLayerUrls,'{}');?>;
  var STROKES=<?php echo lssd_map_json_for_script($strokes,'[]');?>;
  var REVISION=<?php echo $mapRevision;?>;
  var MAP_LAYER=<?php echo lssd_map_json_for_script($currentLayer,'"atlas"');?>;
  var VIEWPORT=<?php echo lssd_map_json_for_script($viewport,'null');?>;
  var AJAX=<?php echo lssd_map_json_for_script(SITE_URL.'/pages/lssd/ajax/','""');?>;
  var CSRF=<?php echo lssd_map_json_for_script($csrf,'""');?>;
  var PALETTE=['#ef4444','#f97316','#eab308','#22c55e','#10b981','#a855f7','#f9fafb','#000000'];

  window.LSSD_MAP_CONFIG={
    mapSources:MAP_SOURCES,map_layer:MAP_LAYER,
    strokes:STROKES,revision:REVISION,viewport:VIEWPORT,
    ajaxBase:AJAX,csrf:CSRF,mapId:null /* pas de collab en édition */
  };

  var editor=null;

  function boot(){
    if(typeof LssdMapEditor==='undefined'){console.error('LssdMapEditor introuvable');return;}

    var colorsEl=document.getElementById('editColors');
    if(colorsEl){
      PALETTE.forEach(function(c){
        var b=document.createElement('button');
        b.type='button';b.style.background=c;b.title=c;
        b.addEventListener('click',function(){
          colorsEl.querySelectorAll('button').forEach(function(x){x.classList.remove('on');});
          b.classList.add('on');
          if(editor) editor.color=c;
        });
        colorsEl.appendChild(b);
      });
      var f=colorsEl.querySelector('button');if(f) f.classList.add('on');
    }

    try {
      editor=new LssdMapEditor({
        vpId:   'edit-map-viewport',
        planeId:'edit-map-plane',
        imgId:  'edit-map-img',
        cvId:   'edit-map-canvas',
        zinId:  'editZoomIn',
        zoutId: 'editZoomOut',
        collab: false  /* pas de polling en mode édition */
      });
      editor.init();
      window._editMapEditor=editor;
    } catch(e){
      console.error('[LSSD Map edit] Erreur init:', e);
      return;
    }

    /* Bouton pinceau ON/OFF */
    var brushBtn=document.getElementById('editBrushToggle');
    if(brushBtn){
      brushBtn.addEventListener('click',function(){
        var next=!editor.brush;
        editor.setBrush(next);
        brushBtn.classList.toggle('is-on',next);
        brushBtn.innerHTML = next
          ? '<i class="fas fa-paintbrush"></i> Pinceau : ON'
          : '<i class="fas fa-hand"></i> Pinceau : OFF';
      });
    }

    var toolEl=document.getElementById('editTool');
    var widthEl=document.getElementById('editWidth');
    if(toolEl)  toolEl.addEventListener('change',function(){editor.tool=this.value;});
    if(widthEl) widthEl.addEventListener('input',function(){editor.lw=parseInt(this.value,10)||3;});

    /* Changement de fond */
    document.querySelectorAll('input[name="editMapLayer"]').forEach(function(radio){
      radio.addEventListener('change',function(){
        document.querySelectorAll('.layer-card').forEach(function(c){c.classList.remove('selected');});
        var card=document.getElementById('card-'+radio.value);
        if(card) card.classList.add('selected');
        var loadEl=document.getElementById('mapLayerLoading');
        var img=document.getElementById('edit-map-img');
        if(loadEl) loadEl.classList.add('on');
        if(img){
          var done=false;
          var finish=function(){if(!done){done=true;if(loadEl) loadEl.classList.remove('on');}};
          img.addEventListener('load',finish,{once:true});
          img.addEventListener('error',finish,{once:true});
          setTimeout(finish,8000);
        }
        editor.setLayerMode(radio.value);
        window.LSSD_MAP_CONFIG.map_layer=radio.value;
      });
    });
    document.querySelectorAll('.layer-card').forEach(function(card){
      card.addEventListener('click',function(){
        var radio=card.querySelector('input[type=radio]');
        if(radio&&!radio.checked){radio.checked=true;radio.dispatchEvent(new Event('change',{bubbles:true}));}
      });
    });
  }

  /* ── Sauvegarde ── */
  var btnSave=document.getElementById('btnSaveEdit');
  var errEl=document.getElementById('editSaveError');
  var okEl=document.getElementById('editSaveOk');

  function hideMessages(){ if(errEl) errEl.style.display='none'; if(okEl) okEl.style.display='none'; }

  if(btnSave){
    btnSave.addEventListener('click',function(){
      hideMessages();
      var title=((document.getElementById('editMapTitle')||{}).value||'').trim();
      var nature=(document.getElementById('editMapNature')||{}).value||'autre';
      var layerEl=document.querySelector('input[name="editMapLayer"]:checked');
      var mapLayer=layerEl?layerEl.value:'atlas';

      if(title.length<2){if(errEl){errEl.textContent='Titre trop court.';errEl.style.display='block';}return;}
      if(title.length>255){if(errEl){errEl.textContent='Titre trop long.';errEl.style.display='block';}return;}

      btnSave.disabled=true;
      btnSave.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sauvegarde…';

      var strokes=editor?(editor.strokes||[]):STROKES;
      var viewport=editor?editor.getViewport():null;

      fetch(AJAX+'map_update.php',{
        method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',
        body:JSON.stringify({map_id:MAP_ID,title:title,nature:nature,
          map_layer:mapLayer,strokes:strokes,viewport:viewport,csrf:CSRF})
      })
      .then(function(r){return r.json();})
      .then(function(j){
        if(!j.ok) throw new Error(j.error||'Erreur serveur');
        if(j.revision) REVISION=j.revision;
        if(okEl){okEl.style.display='block';}
        setTimeout(function(){if(okEl) okEl.style.display='none';},3000);
      })
      .catch(function(err){
        if(errEl){errEl.textContent=err.message;errEl.style.display='block';}
      })
      .finally(function(){
        btnSave.disabled=false;
        btnSave.innerHTML='<i class="fas fa-save"></i> Sauvegarder';
      });
    });
  }

  /* ── Effacer tracés ── */
  var btnClear=document.getElementById('btnClearStrokes');
  if(btnClear){
    btnClear.addEventListener('click',function(){
      if(!confirm('Supprimer tous les tracés de ce plan ? Cette action sera effective à la prochaine sauvegarde.')) return;
      if(editor){ editor.strokes=[]; if(typeof editor._draw==='function') editor._draw(); }
    });
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',function(){setTimeout(boot,0);});
  } else { setTimeout(boot,0); }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>