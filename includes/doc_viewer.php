<?php
/**
 * includes/doc_viewer.php
 * ─────────────────────────────────────────────────────────────────────────
 * Visionneuse de documents intégrée (modale plein écran) partagée par les
 * panels. Évite l'ouverture d'un nouvel onglet : les certificats/ordonnances
 * (pages HTML) s'affichent dans un <iframe>, les pièces d'identité (images)
 * dans un <img> avec repli propre si le fichier est manquant/invalide.
 *
 * Usage côté page :
 *   require_once ROOT_PATH . '/includes/doc_viewer.php';   // une fois, dans le <body>
 *   <a href="URL" onclick="mdtOpenDoc('URL','doc','Titre');return false;">Voir</a>
 *   <a href="URL" onclick="mdtOpenDoc('URL','img','Pièce');return false;">Photo</a>
 */
if (!defined('MDT_DOC_VIEWER_RENDERED')):
    define('MDT_DOC_VIEWER_RENDERED', 1);
?>
<div id="mdtDocViewer" style="position:fixed;inset:0;z-index:100000;display:none;background:rgba(3,6,15,.85);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:2vh 2vw">
  <div style="position:relative;width:min(1050px,96vw);height:94vh;background:#0f1220;border:1px solid rgba(255,255,255,.15);border-radius:14px;overflow:hidden;box-shadow:0 25px 80px rgba(0,0,0,.6);display:flex;flex-direction:column">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.6rem .9rem;background:rgba(255,255,255,.04);border-bottom:1px solid rgba(255,255,255,.08)">
      <div id="mdtDocViewerTitle" style="font:600 .85rem/1.2 system-ui,Segoe UI,sans-serif;color:#e2e8f0;display:flex;align-items:center;gap:.5rem;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><i class="fas fa-file-lines"></i> Document</div>
      <div style="display:flex;gap:.5rem;flex-shrink:0">
        <a id="mdtDocViewerOpen" href="#" target="_blank" rel="noopener" title="Ouvrir dans un onglet" style="width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);text-decoration:none"><i class="fas fa-up-right-from-square" style="font-size:.78rem"></i></a>
        <button type="button" onclick="mdtCloseDoc()" title="Fermer" style="width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fca5a5;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);cursor:pointer;font-family:inherit"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div id="mdtDocViewerBody" style="flex:1;overflow:auto;background:#0b0e1a;display:flex;align-items:center;justify-content:center"></div>
  </div>
</div>
<script>
(function(){
  if (window.mdtOpenDoc) return;
  var v = document.getElementById('mdtDocViewer');
  var body = document.getElementById('mdtDocViewerBody');
  var titleEl = document.getElementById('mdtDocViewerTitle');
  var openEl = document.getElementById('mdtDocViewerOpen');
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
  function errBox(){
    var d=document.createElement('div');
    d.style.cssText='color:#94a3b8;font:500 .9rem system-ui,sans-serif;text-align:center;padding:2.5rem 1.5rem';
    d.innerHTML='<i class="fas fa-triangle-exclamation" style="font-size:1.7rem;display:block;margin-bottom:.7rem;color:#fca5a5"></i>Fichier introuvable ou lien invalide.';
    return d;
  }
  window.mdtDocErr = function(imgEl){ if(imgEl && imgEl.parentNode){ imgEl.parentNode.replaceChild(errBox(), imgEl); } };
  window.mdtOpenDoc = function(url, kind, title){
    if(!url){ return false; }
    kind = kind || 'doc';
    titleEl.innerHTML = '<i class="fas fa-'+(kind==='img'?'image':'file-lines')+'"></i> '+esc(title || (kind==='img'?'Pièce d\'identité':'Document'));
    openEl.href = url;
    if(kind === 'img'){
      var img=document.createElement('img');
      img.src=url; img.alt=''; img.referrerPolicy='no-referrer';
      img.style.cssText='max-width:100%;max-height:100%;object-fit:contain;background:#111';
      img.onerror=function(){ window.mdtDocErr(img); };
      body.innerHTML=''; body.appendChild(img);
    } else {
      var f=document.createElement('iframe');
      f.src=url; f.referrerPolicy='no-referrer';
      f.style.cssText='width:100%;height:100%;border:0;background:#fff';
      body.innerHTML=''; body.appendChild(f);
    }
    v.style.display='flex'; document.body.style.overflow='hidden';
    return false;
  };
  window.mdtCloseDoc = function(){ v.style.display='none'; body.innerHTML=''; document.body.style.overflow=''; };
  v.addEventListener('click', function(e){ if(e.target===v) window.mdtCloseDoc(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && v.style.display==='flex') window.mdtCloseDoc(); });
})();
</script>
<?php endif; ?>
