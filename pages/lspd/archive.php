<?php
/**
 * CDN interne LSPD — stockage et partage d'images officiers.
 * pages/lspd/archive.php
 */
require_once __DIR__ . '/../../config/config.php';
requireLogin();
require_once __DIR__ . '/../../includes/faction_acl.php';
require_once __DIR__ . '/../../includes/lspd_cdn.php';

$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);
lspd_cdn_ensure_table($db);

$canView   = faction_acl_can($db, $uid, 'LSPD', 'cdn.view');
$canUpload = faction_acl_can($db, $uid, 'LSPD', 'cdn.upload');
$canDelete = faction_acl_can($db, $uid, 'LSPD', 'cdn.delete');

// ── AJAX ────────────────────────────────────────────────────────────────────
function cdn_respond($arr) { header('Content-Type: application/json'); echo json_encode($arr); exit; }

$__cdnAction = $_POST['cdn_action'] ?? $_GET['cdn_action'] ?? '';
if ($__cdnAction !== '') {
    if (!$canView) cdn_respond(['success' => false, 'error' => 'Accès refusé']);

    if ($__cdnAction === 'list') {
        $rows = lspd_cdn_all($db);
        $out = array_map(function ($r) {
            return [
                'id'    => (int)$r['id'],
                'title' => $r['title'],
                'url'   => $r['public_url'],
                'agent' => mdt_rp_display_name($r['uploader_first'] ?? null, $r['uploader_last'] ?? null, $r['uploader_name'] ?? ''),
                'date'  => $r['created_at'] ? date('d/m/Y H:i', strtotime($r['created_at'])) : '',
            ];
        }, $rows);
        cdn_respond(['success' => true, 'images' => $out]);
    }

    if ($__cdnAction === 'add_url') {
        if (!$canUpload) cdn_respond(['success' => false, 'error' => 'Permission requise']);
        cdn_respond(lspd_cdn_store_url($db, $_POST['url'] ?? '', trim($_POST['title'] ?? ''), $uid));
    }

    if ($__cdnAction === 'upload') {
        if (!$canUpload) cdn_respond(['success' => false, 'error' => 'Permission requise']);
        cdn_respond(lspd_cdn_store_upload($db, $_FILES['file'] ?? [], trim($_POST['title'] ?? ''), $uid));
    }

    if ($__cdnAction === 'extract_zip') {
        if (!$canUpload) cdn_respond(['success' => false, 'error' => 'Permission requise']);
        cdn_respond(lspd_cdn_extract_zip($_FILES['file'] ?? []));
    }

    if ($__cdnAction === 'commit_zip') {
        if (!$canUpload) cdn_respond(['success' => false, 'error' => 'Permission requise']);
        $token    = $_POST['token'] ?? '';
        $selected = json_decode($_POST['selected'] ?? '[]', true) ?: [];
        $titles   = json_decode($_POST['titles'] ?? '{}', true) ?: [];
        cdn_respond(lspd_cdn_commit_staged($db, $token, $selected, $titles, $uid));
    }

    if ($__cdnAction === 'delete') {
        if (!$canDelete) cdn_respond(['success' => false, 'error' => 'Permission requise']);
        cdn_respond(lspd_cdn_delete($db, (int)($_POST['id'] ?? 0)));
    }

    cdn_respond(['success' => false, 'error' => 'Action inconnue']);
}

$pageTitle = 'Archives CDN';
include __DIR__ . '/includes/header.php';
?>
<style>
.cdn-toolbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.4rem;flex-wrap:wrap}
.cdn-search{display:flex;align-items:center;gap:.5rem;padding:.55rem .85rem;background:#020508;border:1px solid rgba(37,99,235,.2);border-radius:8px;min-width:260px;flex:1;max-width:380px}
.cdn-search i{color:var(--txt-s)}.cdn-search input{flex:1;background:none;border:none;outline:none;color:var(--txt);font-family:inherit;font-size:.84rem}
.cdn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1.1rem}
.cdn-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:13px;overflow:hidden;transition:all .18s;display:flex;flex-direction:column}
.cdn-card:hover{border-color:var(--bdr-h);transform:translateY(-2px)}
.cdn-thumb{aspect-ratio:16/10;background:#020508 center/cover no-repeat;position:relative;cursor:pointer;display:grid;place-items:center}
.cdn-thumb .ph{color:var(--txt-m);font-size:1.6rem}
.cdn-body{padding:.8rem .9rem;flex:1;display:flex;flex-direction:column;gap:.4rem}
.cdn-ctitle{font-size:.86rem;font-weight:700;color:var(--txt);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cdn-meta{font-size:.7rem;color:var(--txt-s);display:flex;align-items:center;gap:.35rem}
.cdn-meta i{color:var(--blue-l);font-size:.62rem}
.cdn-actions{display:flex;gap:.4rem;padding:.7rem .9rem;border-top:1px solid var(--bdr)}
.cdn-actions .btn{flex:1;justify-content:center}
.cdn-empty{text-align:center;padding:3.5rem 1rem;color:var(--txt-s)}
.cdn-empty i{font-size:2.4rem;color:var(--txt-m);margin-bottom:.8rem;display:block}
/* tabs modal */
.cdn-tabs{display:flex;gap:.4rem;margin-bottom:1.1rem;background:#020508;border:1px solid var(--bdr);border-radius:9px;padding:.3rem}
.cdn-tab{flex:1;padding:.5rem;text-align:center;border-radius:7px;font-size:.8rem;font-weight:600;color:var(--txt-s);cursor:pointer;transition:all .15s}
.cdn-tab.active{background:rgba(37,99,235,.16);color:var(--blue-xl)}
.cdn-pane{display:none}.cdn-pane.active{display:block}
.cdn-drop{border:2px dashed rgba(37,99,235,.3);border-radius:11px;padding:1.6rem;text-align:center;color:var(--txt-s);cursor:pointer;transition:all .15s}
.cdn-drop:hover{border-color:var(--blue);background:rgba(37,99,235,.04)}
.cdn-drop i{font-size:1.7rem;color:var(--blue-l);margin-bottom:.5rem;display:block}
.cdn-drop .fn{color:var(--blue-xl);font-weight:600;margin-top:.4rem;font-size:.8rem}
/* zip gallery */
.cdn-zip-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.6rem;max-height:46vh;overflow-y:auto;margin-top:.4rem}
.cdn-zip-item{position:relative;border:2px solid transparent;border-radius:9px;overflow:hidden;cursor:pointer;aspect-ratio:1;background:#020508 center/cover no-repeat}
.cdn-zip-item.sel{border-color:var(--blue)}
.cdn-zip-item .chk{position:absolute;top:5px;right:5px;width:20px;height:20px;border-radius:50%;background:rgba(0,0,0,.6);border:1px solid #fff;display:grid;place-items:center;color:#fff;font-size:.6rem;opacity:.5}
.cdn-zip-item.sel .chk{background:var(--blue);opacity:1}
/* lightbox */
.cdn-lb-img{max-width:100%;max-height:62vh;border-radius:10px;display:block;margin:0 auto}
</style>

<div class="cdn-toolbar">
  <div class="cdn-search"><i class="fas fa-search"></i><input type="text" id="cdnSearch" placeholder="Rechercher une image..."></div>
  <?php if ($canUpload): ?>
  <button class="btn btn-primary" onclick="LSPD.openModal('cdnAddModal')"><i class="fas fa-plus"></i> Ajouter une image</button>
  <?php endif; ?>
</div>

<div id="cdnGrid" class="cdn-grid"></div>

<!-- ══ MODAL AJOUT ══ -->
<div class="modal-ov hidden" id="cdnAddModal">
  <div class="modal">
    <div class="modal-hd"><h2><i class="fas fa-image"></i> Ajouter une image</h2>
      <button class="modal-cl" onclick="LSPD.closeModal('cdnAddModal')">×</button></div>
    <div class="modal-bd">
      <div class="form-group" style="margin-bottom:1rem">
        <label class="form-label">Titre <span style="color:var(--red)">*</span></label>
        <input type="text" id="cdnTitle" class="form-input" placeholder="Titre de l'image" maxlength="200">
      </div>
      <div class="cdn-tabs">
        <div class="cdn-tab active" data-tab="url" onclick="cdnTab('url')">URL directe</div>
        <div class="cdn-tab" data-tab="file" onclick="cdnTab('file')">Fichier</div>
        <div class="cdn-tab" data-tab="zip" onclick="cdnTab('zip')">Archive ZIP</div>
      </div>

      <div class="cdn-pane active" data-pane="url">
        <div class="form-group"><label class="form-label">URL de l'image</label>
          <input type="url" id="cdnUrl" class="form-input" placeholder="https://..."></div>
      </div>

      <div class="cdn-pane" data-pane="file">
        <div class="cdn-drop" id="cdnFileDrop" onclick="document.getElementById('cdnFileInput').click()">
          <i class="fas fa-cloud-arrow-up"></i>Cliquez pour choisir un fichier<div style="font-size:.72rem;margin-top:.3rem">PNG, JPG, GIF, WEBP — max 8 Mo</div>
          <div class="fn" id="cdnFileName"></div>
        </div>
        <input type="file" id="cdnFileInput" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none">
      </div>

      <div class="cdn-pane" data-pane="zip">
        <div class="cdn-drop" id="cdnZipDrop" onclick="document.getElementById('cdnZipInput').click()">
          <i class="fas fa-file-zipper"></i>Cliquez pour choisir une archive .zip<div style="font-size:.72rem;margin-top:.3rem">Les images seront extraites pour sélection</div>
          <div class="fn" id="cdnZipName"></div>
        </div>
        <input type="file" id="cdnZipInput" accept=".zip,application/zip" style="display:none">
        <div id="cdnZipResult"></div>
      </div>
    </div>
    <div class="modal-ft">
      <button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('cdnAddModal')">Annuler</button>
      <button type="button" class="btn btn-primary" id="cdnSubmitBtn" onclick="cdnSubmit()"><i class="fas fa-check"></i> Ajouter</button>
    </div>
  </div>
</div>

<!-- ══ LIGHTBOX ══ -->
<div class="modal-ov hidden" id="cdnLightbox">
  <div class="modal modal-lg">
    <div class="modal-hd"><h2><i class="fas fa-image"></i> <span id="cdnLbTitle"></span></h2>
      <button class="modal-cl" onclick="LSPD.closeModal('cdnLightbox')">×</button></div>
    <div class="modal-bd"><img id="cdnLbImg" class="cdn-lb-img" src="" alt=""></div>
    <div class="modal-ft">
      <button type="button" class="btn btn-secondary" id="cdnLbCopy"><i class="fas fa-link"></i> Copier le lien</button>
    </div>
  </div>
</div>

<script>
const CDN = {
  canDelete: <?php echo $canDelete ? 'true' : 'false'; ?>,
  images: [],
  mode: 'url',
  zipToken: null,
  zipSelected: new Set(),

  esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;},

  async load(){
    const r = await fetch('archive.php?cdn_action=list', {headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d = await r.json();
    CDN.images = d.images || [];
    CDN.render();
  },

  render(){
    const q = (document.getElementById('cdnSearch').value||'').toLowerCase();
    const list = CDN.images.filter(im => !q || (im.title||'').toLowerCase().includes(q) || (im.agent||'').toLowerCase().includes(q));
    const grid = document.getElementById('cdnGrid');
    if(!list.length){ grid.innerHTML = '<div class="cdn-empty" style="grid-column:1/-1"><i class="fas fa-images"></i>Aucune image dans le CDN.</div>'; return; }
    grid.innerHTML = list.map(im => `
      <div class="cdn-card">
        <div class="cdn-thumb" style="background-image:url('${CDN.esc(im.url)}')" onclick="CDN.lightbox(${im.id})">
          ${im.url?'':'<span class="ph"><i class="fas fa-image"></i></span>'}
        </div>
        <div class="cdn-body">
          <div class="cdn-ctitle">${CDN.esc(im.title)||'Sans titre'}</div>
          <div class="cdn-meta"><i class="fas fa-user-shield"></i>${CDN.esc(im.agent)||'—'}</div>
          <div class="cdn-meta"><i class="fas fa-clock"></i>${CDN.esc(im.date)}</div>
        </div>
        <div class="cdn-actions">
          <button class="btn btn-secondary btn-sm" onclick="CDN.copy('${CDN.esc(im.url)}')"><i class="fas fa-link"></i> Copier</button>
          ${CDN.canDelete?`<button class="btn btn-danger btn-sm" onclick="CDN.del(${im.id},'${CDN.esc((im.title||'').replace(/'/g,''))}')"><i class="fas fa-trash"></i></button>`:''}
        </div>
      </div>`).join('');
  },

  lightbox(id){
    const im = CDN.images.find(x=>x.id===id); if(!im) return;
    document.getElementById('cdnLbTitle').textContent = im.title||'Sans titre';
    document.getElementById('cdnLbImg').src = im.url;
    document.getElementById('cdnLbCopy').onclick = ()=>CDN.copy(im.url);
    LSPD.openModal('cdnLightbox');
  },

  copy(url){
    if(!url) return;
    const abs = url.startsWith('http') ? url : (location.origin + url);
    navigator.clipboard.writeText(abs).then(()=>LSPD.toast('Lien copié dans le presse-papiers','success'))
      .catch(()=>{ const t=document.createElement('textarea');t.value=abs;document.body.appendChild(t);t.select();document.execCommand('copy');t.remove();LSPD.toast('Lien copié','success'); });
  },

  del(id, title){
    LSPD.confirm({title:'Supprimer l\'image', msg:'Cette image sera <strong>définitivement</strong> supprimée du CDN.', target:title, okLabel:'Supprimer', callback: async()=>{
      const fd=new FormData(); fd.append('cdn_action','delete'); fd.append('id',id);
      const r=await fetch('archive.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
      const d=await r.json();
      if(d.success){ LSPD.toast('Image supprimée','success'); CDN.load(); } else LSPD.toast(d.error||'Erreur','error');
    }});
  },

  async submit(){
    const title = document.getElementById('cdnTitle').value.trim();
    if(!title){ LSPD.toast('Le titre est obligatoire','error'); return; }
    const btn = document.getElementById('cdnSubmitBtn'); btn.disabled = true;
    try {
      if(CDN.mode==='url'){
        const url = document.getElementById('cdnUrl').value.trim();
        if(!url){ LSPD.toast('Renseignez une URL','error'); return; }
        const fd=new FormData(); fd.append('cdn_action','add_url'); fd.append('url',url); fd.append('title',title);
        await CDN.post(fd, 'Image ajoutée');
      } else if(CDN.mode==='file'){
        const f = document.getElementById('cdnFileInput').files[0];
        if(!f){ LSPD.toast('Choisissez un fichier','error'); return; }
        const fd=new FormData(); fd.append('cdn_action','upload'); fd.append('file',f); fd.append('title',title);
        await CDN.post(fd, 'Image ajoutée');
      } else { // zip commit
        if(!CDN.zipToken || !CDN.zipSelected.size){ LSPD.toast('Sélectionnez au moins une image extraite','error'); return; }
        const fd=new FormData(); fd.append('cdn_action','commit_zip'); fd.append('token',CDN.zipToken);
        fd.append('selected', JSON.stringify([...CDN.zipSelected]));
        const titles={}; [...CDN.zipSelected].forEach((f,i)=>titles[f]= CDN.zipSelected.size>1 ? (title+' '+(i+1)) : title);
        fd.append('titles', JSON.stringify(titles));
        await CDN.post(fd, 'Images ajoutées');
      }
    } finally { btn.disabled = false; }
  },

  async post(fd, okMsg){
    const r=await fetch('archive.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d=await r.json();
    if(d.success){ LSPD.toast(okMsg,'success'); LSPD.closeModal('cdnAddModal'); CDN.reset(); CDN.load(); }
    else LSPD.toast(d.error||'Erreur','error');
  },

  async extractZip(file){
    const res=document.getElementById('cdnZipResult');
    res.innerHTML='<div style="text-align:center;padding:1rem;color:var(--txt-s)"><i class="fas fa-spinner fa-spin"></i> Extraction...</div>';
    const fd=new FormData(); fd.append('cdn_action','extract_zip'); fd.append('file',file);
    const r=await fetch('archive.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d=await r.json();
    if(!d.success){ res.innerHTML='<div class="alert alert-error" style="margin:.6rem 0 0">'+CDN.esc(d.error||'Erreur')+'</div>'; return; }
    CDN.zipToken=d.token; CDN.zipSelected=new Set(d.images.map(i=>i.file));
    res.innerHTML='<div style="font-size:.78rem;color:var(--txt-s);margin:.6rem 0 .3rem">'+d.images.length+' image(s) — cliquez pour (dé)sélectionner :</div><div class="cdn-zip-grid">'+
      d.images.map(im=>`<div class="cdn-zip-item sel" data-file="${CDN.esc(im.file)}" style="background-image:url('${CDN.esc(im.url)}')" onclick="CDN.toggleZip(this)"><span class="chk"><i class="fas fa-check"></i></span></div>`).join('')+'</div>';
  },

  toggleZip(el){
    const f=el.dataset.file;
    if(CDN.zipSelected.has(f)){ CDN.zipSelected.delete(f); el.classList.remove('sel'); }
    else { CDN.zipSelected.add(f); el.classList.add('sel'); }
  },

  reset(){
    document.getElementById('cdnTitle').value='';
    document.getElementById('cdnUrl').value='';
    document.getElementById('cdnFileInput').value=''; document.getElementById('cdnFileName').textContent='';
    document.getElementById('cdnZipInput').value=''; document.getElementById('cdnZipName').textContent='';
    document.getElementById('cdnZipResult').innerHTML='';
    CDN.zipToken=null; CDN.zipSelected=new Set();
    cdnTab('url');
  }
};
function cdnTab(t){
  CDN.mode=t;
  document.querySelectorAll('.cdn-tab').forEach(el=>el.classList.toggle('active',el.dataset.tab===t));
  document.querySelectorAll('.cdn-pane').forEach(el=>el.classList.toggle('active',el.dataset.pane===t));
}
function cdnSubmit(){ CDN.submit(); }

document.getElementById('cdnSearch').addEventListener('input', ()=>CDN.render());
document.getElementById('cdnFileInput').addEventListener('change', e=>{ document.getElementById('cdnFileName').textContent = e.target.files[0]?e.target.files[0].name:''; });
document.getElementById('cdnZipInput').addEventListener('change', e=>{ const f=e.target.files[0]; document.getElementById('cdnZipName').textContent=f?f.name:''; if(f) CDN.extractZip(f); });
CDN.load();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
