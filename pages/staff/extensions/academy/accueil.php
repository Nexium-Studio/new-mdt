<?php
// pages/staff/extensions/academy/accueil.php
// Configuration des pages d'accueil immersives Académie (slider, FAQ, annonces, présentation).
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db         = Database::getInstance()->getConnection();
$pageTitle  = 'Académie — Pages d\'accueil';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Académie'], ['label' => 'Pages d\'accueil']];

if (empty($_SESSION['staff_academy_csrf'])) $_SESSION['staff_academy_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['staff_academy_csrf'];

$factions  = academy_factions();
$factionKeys = array_keys($factions);
$selectedFK = $_GET['faction'] ?? ($factionKeys[0] ?? 'LSPD');
if (!isset($factions[$selectedFK])) $selectedFK = $factionKeys[0];
$meta       = academy_faction_meta($selectedFK);
$cfg        = academy_homepage_config($selectedFK);
$images     = academy_homepage_images($selectedFK, false);
$faq        = academy_homepage_faq($selectedFK, false);
$announces  = academy_homepage_announcements($selectedFK, false);
$kinds      = academy_announcement_kinds();

include __DIR__ . '/../../includes/header.php';
?>

<style>
:root{--muted-2:var(--text-secondary,#64748b);--border-2:rgba(255,255,255,.18);--accent:var(--purple,#8b5cf6)}
.acm-faction-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;padding:14px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:12px}
.acm-fac-btn{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:9px;background:rgba(255,255,255,.03);border:1px solid var(--border);color:var(--text-primary);font-size:.82rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s}
.acm-fac-btn:hover{border-color:rgba(255,255,255,.18);background:rgba(255,255,255,.05)}
.acm-fac-btn.on{background:var(--fac-c);border-color:var(--fac-c);color:#fff;box-shadow:0 6px 16px -6px var(--fac-c)}
.acm-fac-btn .fac-ic{width:22px;height:22px;border-radius:6px;display:grid;place-items:center;background:var(--fac-c);color:#fff;font-size:.7rem}
.acm-fac-btn.on .fac-ic{background:rgba(255,255,255,.22)}

.acm-tabs{display:flex;gap:4px;margin-bottom:18px;border-bottom:1px solid var(--border);overflow-x:auto}
.acm-tab{padding:11px 18px;background:none;border:0;color:var(--text-secondary);font-size:.85rem;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;transition:all .2s;display:inline-flex;align-items:center;gap:.5rem;white-space:nowrap}
.acm-tab:hover{color:var(--text-primary)}
.acm-tab.on{color:var(--purple);border-color:var(--purple)}
.acm-pane{display:none}
.acm-pane.on{display:block;animation:acmFade .2s ease}
@keyframes acmFade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}

.acm-grid2{display:grid;grid-template-columns:1.4fr 1fr;gap:14px}
@media(max-width:900px){.acm-grid2{grid-template-columns:1fr}}

.acm-img-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
.acm-img-card{position:relative;background:var(--bg-elevated);border:1px solid var(--border);border-radius:12px;overflow:hidden;transition:border-color .2s}
.acm-img-card:hover{border-color:rgba(255,255,255,.18)}
.acm-img-thumb{width:100%;aspect-ratio:16/10;object-fit:cover;display:block;background:#0a0a0f}
.acm-img-meta{padding:10px}
.acm-img-actions{display:flex;gap:6px;justify-content:space-between;align-items:center;margin-top:8px}
.acm-img-card.off{opacity:.45}
.acm-img-badge{position:absolute;top:8px;left:8px;padding:3px 8px;border-radius:50px;background:rgba(0,0,0,.65);color:#fff;font-size:.65rem;font-weight:700;backdrop-filter:blur(6px)}

.acm-list{display:flex;flex-direction:column;gap:8px}
.acm-list-row{display:flex;gap:12px;align-items:flex-start;padding:12px 14px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:11px;transition:border-color .2s}
.acm-list-row:hover{border-color:rgba(255,255,255,.18)}
.acm-list-row.off{opacity:.5}
.acm-list-body{flex:1;min-width:0}
.acm-list-title{font-weight:700;font-size:.92rem;margin-bottom:.2rem;display:flex;align-items:center;gap:.4rem}
.acm-list-meta{font-size:.74rem;color:var(--muted-2);display:flex;flex-wrap:wrap;gap:.55rem;align-items:center}
.acm-list-actions{display:flex;gap:6px;flex-shrink:0}

.acm-color{display:inline-flex;align-items:center;gap:6px}
.acm-color input[type=color]{width:42px;height:32px;border:0;background:none;padding:0;cursor:pointer}

.acm-empty{text-align:center;padding:36px 14px;color:var(--muted-2);background:rgba(255,255,255,.02);border:1px dashed var(--border);border-radius:12px}
.acm-empty i{font-size:1.8rem;opacity:.4;margin-bottom:.4rem;display:block}

.acm-toast{position:fixed;bottom:22px;right:22px;background:#10b981;color:#fff;padding:11px 18px;border-radius:9px;font-weight:600;font-size:.85rem;z-index:9999;box-shadow:0 12px 30px -10px rgba(16,185,129,.5);transform:translateY(20px);opacity:0;transition:all .25s}
.acm-toast.on{transform:none;opacity:1}
.acm-toast.err{background:#ef4444;box-shadow:0 12px 30px -10px rgba(239,68,68,.5)}

.acm-drop{display:block;border:2px dashed var(--border-2);border-radius:14px;padding:28px;text-align:center;cursor:pointer;transition:all .25s;background:rgba(255,255,255,.02);color:var(--muted-2)}
.acm-drop:hover,.acm-drop.dragover{border-color:var(--purple);color:var(--purple);background:rgba(139,92,246,.05)}
.acm-drop i{font-size:1.8rem;margin-bottom:.5rem;display:block}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1>Pages d'accueil immersives</h1>
    <p>Configurez la page de présentation publique de chaque faction (slider, présentation, FAQ, annonces).</p>
  </div>
  <div class="page-header-right">
    <a href="<?php echo SITE_URL; ?>/pages/academy/<?php echo $meta['slug']; ?>.php" target="_blank" class="btn btn-secondary">
      <i class="fa-solid fa-external-link-alt"></i> Voir la page publique
    </a>
  </div>
</div>

<!-- Sélecteur de faction -->
<div class="acm-faction-bar">
  <?php foreach ($factions as $fk => $m): ?>
  <a href="?faction=<?php echo $fk; ?>" class="acm-fac-btn <?php echo $fk === $selectedFK ? 'on' : ''; ?>" style="--fac-c:<?php echo $m['color']; ?>">
    <span class="fac-ic"><i class="fa-solid fa-<?php echo $m['icon']; ?>"></i></span>
    <?php echo htmlspecialchars($m['name']); ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Onglets -->
<div class="acm-tabs">
  <button class="acm-tab on" data-tab="pres"><i class="fa-solid fa-pen-nib"></i> Présentation</button>
  <button class="acm-tab" data-tab="slider"><i class="fa-solid fa-images"></i> Slider <span class="badge" style="font-size:.65rem"><?php echo count($images); ?></span></button>
  <button class="acm-tab" data-tab="faq"><i class="fa-solid fa-circle-question"></i> FAQ <span class="badge" style="font-size:.65rem"><?php echo count($faq); ?></span></button>
  <button class="acm-tab" data-tab="ann"><i class="fa-solid fa-bullhorn"></i> Annonces <span class="badge" style="font-size:.65rem"><?php echo count($announces); ?></span></button>
</div>

<!-- ========================================================================
     PANE : PRÉSENTATION
======================================================================== -->
<div class="acm-pane on" id="pane-pres">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-pen-nib"></i> Contenu de présentation</div></div>
    <div class="card-body">
      <form id="formCfg">
        <div class="acm-grid2">
          <div>
            <div class="form-group">
              <label class="form-label">Titre principal (héro)</label>
              <input type="text" name="hero_title" class="form-control" maxlength="255" value="<?php echo htmlspecialchars($cfg['hero_title']); ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Sous-titre</label>
              <input type="text" name="hero_subtitle" class="form-control" maxlength="255" value="<?php echo htmlspecialchars($cfg['hero_subtitle']); ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Devise / slogan court</label>
              <input type="text" name="motto" class="form-control" maxlength="255" value="<?php echo htmlspecialchars($cfg['motto']); ?>" placeholder="Ex: Servir et protéger">
            </div>
            <div class="form-group">
              <label class="form-label">Texte de présentation</label>
              <div id="presEditor" style="min-height:220px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px"></div>
              <textarea name="presentation_text" id="presText" style="display:none"><?php echo htmlspecialchars($cfg['presentation_text']); ?></textarea>
            </div>
          </div>
          <div>
            <div class="form-group">
              <label class="form-label">Libellé bouton candidature</label>
              <input type="text" name="cta_primary_label" class="form-control" maxlength="64" value="<?php echo htmlspecialchars($cfg['cta_primary_label']); ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Libellé bouton QCM</label>
              <input type="text" name="cta_secondary_label" class="form-control" maxlength="64" value="<?php echo htmlspecialchars($cfg['cta_secondary_label']); ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Couleur d'accent</label>
              <div class="acm-color">
                <input type="color" name="accent_color" id="accentColor" value="<?php echo htmlspecialchars($cfg['accent_color'] ?: $meta['color']); ?>">
                <input type="text" id="accentColorTxt" class="form-control" value="<?php echo htmlspecialchars($cfg['accent_color'] ?: $meta['color']); ?>" maxlength="9" style="max-width:120px;font-family:monospace">
              </div>
              <div style="font-size:.72rem;color:var(--muted-2);margin-top:6px">Par défaut : couleur de la faction (<?php echo $meta['color']; ?>)</div>
            </div>
            <div class="form-group">
              <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="is_published" value="1" <?php echo (int)$cfg['is_published'] ? 'checked' : ''; ?>>
                Page publiée (visible sur l'Académie publique)
              </label>
            </div>
            <div style="background:rgba(139,92,246,.05);border:1px solid rgba(139,92,246,.18);border-radius:10px;padding:12px;font-size:.78rem;color:var(--muted-2)">
              <i class="fa-solid fa-circle-info" style="color:#a855f7"></i>
              <strong>Astuce :</strong> Le bouton « Passer le QCM » s'affiche automatiquement aux candidats dont la candidature est en statut <em>« En examen »</em>.
            </div>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:14px;gap:8px">
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Enregistrer la présentation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ========================================================================
     PANE : SLIDER
======================================================================== -->
<div class="acm-pane" id="pane-slider">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-cloud-arrow-up"></i> Ajouter des images</div></div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:14px">
        <div>
          <label for="imgInput" class="acm-drop" id="dropZone">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <div style="font-weight:700;color:var(--text-primary);margin-bottom:.2rem">Glissez vos images ici ou cliquez pour sélectionner</div>
            <div style="font-size:.78rem">JPG, PNG, WEBP, GIF — 4 Mo max — multi-upload supporté</div>
          </label>
          <input type="file" id="imgInput" multiple accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">
        </div>
        <div>
          <div style="background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:14px;padding:18px;height:100%;display:flex;flex-direction:column;gap:.5rem">
            <div style="font-weight:700;display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-link" style="color:var(--purple)"></i> Ajouter via URL externe</div>
            <div style="font-size:.74rem;color:var(--muted-2)">Hotlink direct (Imgur, CDN, Discord…). L'image n'est pas téléchargée sur le serveur.</div>
            <input type="url" id="urlInput" class="form-control" placeholder="https://exemple.com/image.jpg" style="margin-top:6px">
            <input type="text" id="urlCaption" class="form-control" placeholder="Légende (optionnel)" maxlength="255">
            <button type="button" class="btn btn-primary btn-sm" onclick="addUrlImage()" style="margin-top:auto"><i class="fa-solid fa-plus"></i> Ajouter à la galerie</button>
          </div>
        </div>
      </div>
      <div id="uploadProgress" style="margin-top:12px"></div>
    </div>
  </div>

  <div class="card" style="margin-top:14px">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-images"></i> Images du slider (<?php echo count($images); ?>)</div>
      <div style="font-size:.75rem;color:var(--muted-2)">Glissez-déposez les vignettes pour réordonner</div>
    </div>
    <div class="card-body">
      <div class="acm-empty" id="imgEmpty" style="<?php echo empty($images) ? '' : 'display:none'; ?>"><i class="fa-solid fa-images"></i>Aucune image pour le moment.</div>
      <div class="acm-img-grid" id="imgGrid" style="<?php echo empty($images) ? 'display:none' : ''; ?>">
        <?php foreach ($images as $img):
          $isUrl = (bool)preg_match('#^https?://#i', $img['image_path']);
        ?>
        <div class="acm-img-card <?php echo (int)$img['is_active'] ? '' : 'off'; ?>" draggable="true" data-id="<?php echo (int)$img['id']; ?>">
          <div class="acm-img-badge">#<?php echo (int)$img['sort_order']; ?></div>
          <?php if ($isUrl): ?><div class="acm-img-badge" style="left:auto;right:8px;background:rgba(139,92,246,.85)" title="Image distante (URL)"><i class="fa-solid fa-link"></i> URL</div><?php endif; ?>
          <img src="<?php echo htmlspecialchars(academy_image_src($img['image_path'])); ?>" alt="" class="acm-img-thumb" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.background='#1a0e0e';this.alt='⚠ Image inaccessible'">
          <div class="acm-img-meta">
            <input type="text" class="form-control" data-cap="<?php echo (int)$img['id']; ?>" value="<?php echo htmlspecialchars($img['caption'] ?? ''); ?>" placeholder="Légende (optionnel)" maxlength="255" style="font-size:.78rem">
            <div class="acm-img-actions">
              <label style="display:flex;align-items:center;gap:5px;font-size:.76rem;color:var(--muted-2);cursor:pointer">
                <input type="checkbox" data-toggle="<?php echo (int)$img['id']; ?>" <?php echo (int)$img['is_active'] ? 'checked' : ''; ?>>
                Actif
              </label>
              <div style="display:flex;gap:4px">
                <button class="btn btn-sm btn-secondary" type="button" onclick="saveCaption(<?php echo (int)$img['id']; ?>)" title="Enregistrer légende"><i class="fa-solid fa-save"></i></button>
                <button class="btn btn-sm btn-danger" type="button" onclick="deleteImage(<?php echo (int)$img['id']; ?>)" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================================
     PANE : FAQ
======================================================================== -->
<div class="acm-pane" id="pane-faq">
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-circle-question"></i> Questions fréquentes (<?php echo count($faq); ?>)</div>
      <button class="btn btn-primary btn-sm" type="button" onclick="openFaqModal()"><i class="fa-solid fa-plus"></i> Ajouter</button>
    </div>
    <div class="card-body">
      <?php if (empty($faq)): ?>
      <div class="acm-empty"><i class="fa-solid fa-circle-question"></i>Aucune question pour le moment.</div>
      <?php else: ?>
      <div class="acm-list">
        <?php foreach ($faq as $q): ?>
        <div class="acm-list-row <?php echo (int)$q['is_active'] ? '' : 'off'; ?>" data-faq='<?php echo htmlspecialchars(json_encode($q), ENT_QUOTES); ?>'>
          <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:1rem;color:var(--purple);min-width:32px;text-align:center;padding-top:2px">#<?php echo (int)$q['sort_order']; ?></div>
          <div class="acm-list-body">
            <div class="acm-list-title"><?php echo htmlspecialchars($q['question']); ?></div>
            <div class="acm-list-meta" style="margin-top:4px;color:var(--text-secondary);font-size:.8rem;line-height:1.5"><?php echo strip_tags($q['answer']); ?></div>
          </div>
          <div class="acm-list-actions">
            <button class="btn btn-sm btn-secondary" type="button" onclick="openFaqModal(this.parentElement.parentElement)" title="Modifier"><i class="fa-solid fa-pen"></i></button>
            <button class="btn btn-sm btn-secondary" type="button" onclick="toggleFaq(<?php echo (int)$q['id']; ?>)" title="Activer/Désactiver"><i class="fa-solid fa-power-off"></i></button>
            <button class="btn btn-sm btn-danger" type="button" onclick="deleteFaq(<?php echo (int)$q['id']; ?>)" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ========================================================================
     PANE : ANNONCES
======================================================================== -->
<div class="acm-pane" id="pane-ann">
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-bullhorn"></i> Annonces &amp; informations (<?php echo count($announces); ?>)</div>
      <button class="btn btn-primary btn-sm" type="button" onclick="openAnnModal()"><i class="fa-solid fa-plus"></i> Ajouter</button>
    </div>
    <div class="card-body">
      <?php if (empty($announces)): ?>
      <div class="acm-empty"><i class="fa-solid fa-bullhorn"></i>Aucune annonce pour le moment.</div>
      <?php else: ?>
      <div class="acm-list">
        <?php foreach ($announces as $a):
          $k = $kinds[$a['kind']] ?? $kinds['info'];
        ?>
        <div class="acm-list-row <?php echo (int)$a['is_active'] ? '' : 'off'; ?>" data-ann='<?php echo htmlspecialchars(json_encode($a), ENT_QUOTES); ?>'>
          <div style="width:38px;height:38px;border-radius:9px;background:<?php echo $k['bg']; ?>;color:<?php echo $k['color']; ?>;display:grid;place-items:center;font-size:1rem;flex-shrink:0"><i class="fa-solid fa-<?php echo htmlspecialchars($a['icon'] ?: $k['icon']); ?>"></i></div>
          <div class="acm-list-body">
            <div class="acm-list-title"><?php echo htmlspecialchars($a['title']); ?></div>
            <div class="acm-list-meta">
              <span style="color:<?php echo $k['color']; ?>;font-weight:700"><?php echo $k['label']; ?></span>
              <span><?php echo htmlspecialchars(mb_strimwidth($a['description'] ?? '', 0, 90, '…')); ?></span>
              <?php if (!empty($a['expires_at'])): ?>
              <span><i class="fa-solid fa-clock"></i> Expire le <?php echo date('d/m/Y H:i', strtotime($a['expires_at'])); ?></span>
              <?php endif; ?>
              <?php if (!empty($a['link_url'])): ?>
              <span><i class="fa-solid fa-link"></i> Lien</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="acm-list-actions">
            <button class="btn btn-sm btn-secondary" type="button" onclick="openAnnModal(this.parentElement.parentElement)" title="Modifier"><i class="fa-solid fa-pen"></i></button>
            <button class="btn btn-sm btn-secondary" type="button" onclick="toggleAnn(<?php echo (int)$a['id']; ?>)" title="Activer/Désactiver"><i class="fa-solid fa-power-off"></i></button>
            <button class="btn btn-sm btn-danger" type="button" onclick="deleteAnn(<?php echo (int)$a['id']; ?>)" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- =============================== MODALES =============================== -->

<!-- Modal FAQ -->
<div class="modal-overlay" id="faqModal" style="position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:200;display:none;place-items:center;padding:20px">
  <div class="modal" style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:16px;width:100%;max-width:640px;padding:24px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
      <h3 id="faqTitle">Nouvelle question</h3>
      <button class="btn btn-secondary btn-icon" onclick="closeFaqModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <input type="hidden" id="faqId" value="">
    <div class="form-group">
      <label class="form-label">Question</label>
      <input type="text" id="faqQ" class="form-control" maxlength="255" placeholder="Ex: Comment rejoindre le LSPD ?">
    </div>
    <div class="form-group">
      <label class="form-label">Réponse</label>
      <div id="faqEditor" style="min-height:160px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group"><label class="form-label">Ordre</label><input type="number" id="faqOrder" class="form-control" value="0"></div>
      <div class="form-group"><label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:28px"><input type="checkbox" id="faqActive" checked> Question active</label></div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px">
      <button type="button" class="btn btn-secondary" onclick="closeFaqModal()">Annuler</button>
      <button type="button" class="btn btn-primary" onclick="saveFaq()"><i class="fa-solid fa-check"></i> Enregistrer</button>
    </div>
  </div>
</div>

<!-- Modal Annonce -->
<div class="modal-overlay" id="annModal" style="position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:200;display:none;place-items:center;padding:20px">
  <div class="modal" style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:16px;width:100%;max-width:640px;padding:24px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
      <h3 id="annTitle">Nouvelle annonce</h3>
      <button class="btn btn-secondary btn-icon" onclick="closeAnnModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <input type="hidden" id="annId" value="">
    <div class="form-group">
      <label class="form-label">Titre</label>
      <input type="text" id="annTitleI" class="form-control" maxlength="255">
    </div>
    <div class="form-group">
      <label class="form-label">Description</label>
      <textarea id="annDesc" class="form-control" rows="3"></textarea>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
      <div class="form-group">
        <label class="form-label">Type</label>
        <select id="annKind" class="form-control">
          <?php foreach ($kinds as $kKey => $kk): ?>
          <option value="<?php echo $kKey; ?>"><?php echo $kk['label']; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Icône (FontAwesome)</label>
        <input type="text" id="annIcon" class="form-control" placeholder="bullhorn" maxlength="64">
      </div>
      <div class="form-group">
        <label class="form-label">Ordre</label>
        <input type="number" id="annOrder" class="form-control" value="0">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
      <div class="form-group">
        <label class="form-label">Lien (optionnel)</label>
        <input type="url" id="annLink" class="form-control" placeholder="https://…">
      </div>
      <div class="form-group">
        <label class="form-label">Libellé du lien</label>
        <input type="text" id="annLinkLabel" class="form-control" placeholder="En savoir plus" maxlength="64">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group">
        <label class="form-label">Date d'expiration (optionnel)</label>
        <input type="datetime-local" id="annExp" class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:28px">
          <input type="checkbox" id="annActive" checked> Annonce active
        </label>
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px">
      <button type="button" class="btn btn-secondary" onclick="closeAnnModal()">Annuler</button>
      <button type="button" class="btn btn-primary" onclick="saveAnn()"><i class="fa-solid fa-check"></i> Enregistrer</button>
    </div>
  </div>
</div>

<div id="toastZone"></div>

<!-- Quill -->
<link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>

<script>
const FACTION  = <?php echo json_encode($selectedFK); ?>;
const CSRF     = <?php echo json_encode($csrf); ?>;
const AJAX_URL = <?php echo json_encode(SITE_URL . '/pages/staff/extensions/academy/ajax/accueil.php'); ?>;

// ── Tabs ───────────────────────────────────────────────────────────────────
document.querySelectorAll('.acm-tab').forEach(t => t.addEventListener('click', () => {
  document.querySelectorAll('.acm-tab').forEach(x => x.classList.remove('on'));
  document.querySelectorAll('.acm-pane').forEach(x => x.classList.remove('on'));
  t.classList.add('on');
  document.getElementById('pane-' + t.dataset.tab).classList.add('on');
}));

// ── Toast ──────────────────────────────────────────────────────────────────
function toast(msg, isErr) {
  const t = document.createElement('div');
  t.className = 'acm-toast' + (isErr ? ' err' : '');
  t.innerHTML = '<i class="fa-solid fa-' + (isErr ? 'circle-xmark' : 'circle-check') + '"></i> ' + msg;
  document.getElementById('toastZone').appendChild(t);
  requestAnimationFrame(() => t.classList.add('on'));
  setTimeout(() => { t.classList.remove('on'); setTimeout(() => t.remove(), 300); }, 2800);
}

// ── AJAX wrapper ───────────────────────────────────────────────────────────
async function api(action, data, opts = {}) {
  const fd = (data instanceof FormData) ? data : new FormData();
  if (!(data instanceof FormData)) Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
  fd.append('action', action);
  fd.append('csrf', CSRF);
  fd.append('faction_key', FACTION);
  const r = await fetch(AJAX_URL, { method: 'POST', body: fd });
  const j = await r.json().catch(() => ({ success: false, error: 'Réponse invalide' }));
  if (!j.success) toast(j.error || 'Erreur', true);
  return j;
}

// ── Quill : présentation ───────────────────────────────────────────────────
const presQuill = new Quill('#presEditor', {
  theme: 'snow',
  modules: { toolbar: [['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], [{ color: [] }], ['link'], ['clean']] }
});
presQuill.root.innerHTML = document.getElementById('presText').value;

// ── Couleur d'accent : sync color ↔ text ───────────────────────────────────
const accentC = document.getElementById('accentColor');
const accentT = document.getElementById('accentColorTxt');
accentC.addEventListener('input', () => { accentT.value = accentC.value; });
accentT.addEventListener('input', () => { if (/^#[0-9A-Fa-f]{6}$/.test(accentT.value)) accentC.value = accentT.value; });

// ── Save présentation config ───────────────────────────────────────────────
document.getElementById('formCfg').addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.set('presentation_text', presQuill.root.innerHTML);
  if (!fd.has('is_published')) fd.set('is_published', '0');
  const j = await api('config_save', fd);
  if (j.success) toast('Présentation enregistrée');
});

// ── Slider : helpers ───────────────────────────────────────────────────────
const grid     = document.getElementById('imgGrid');
const empty    = document.getElementById('imgEmpty');
const sliderTabBadge = document.querySelector('.acm-tab[data-tab="slider"] .badge');

function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function isUrl(p) { return /^https?:\/\//i.test(p || ''); }
function updateSliderCount() {
  if (sliderTabBadge && grid) sliderTabBadge.textContent = grid.querySelectorAll('.acm-img-card').length;
  if (grid && empty) {
    const has = grid.querySelectorAll('.acm-img-card').length > 0;
    grid.style.display  = has ? '' : 'none';
    empty.style.display = has ? 'none' : '';
  }
}

function buildImgCard(img) {
  const card = document.createElement('div');
  card.className = 'acm-img-card' + ((+img.is_active) ? '' : ' off');
  card.draggable = true;
  card.dataset.id = img.id;
  card.innerHTML =
    '<div class="acm-img-badge">#' + (+img.sort_order) + '</div>' +
    (isUrl(img.image_path) ? '<div class="acm-img-badge" style="left:auto;right:8px;background:rgba(139,92,246,.85)" title="Image distante (URL)"><i class="fa-solid fa-link"></i> URL</div>' : '') +
    '<img src="' + escHtml(img.src || img.image_path) + '" alt="" class="acm-img-thumb" loading="lazy" referrerpolicy="no-referrer" ' +
      'onerror="this.style.background=\'#1a0e0e\';this.alt=\'⚠ Image inaccessible\'">' +
    '<div class="acm-img-meta">' +
      '<input type="text" class="form-control" data-cap="' + img.id + '" value="' + escHtml(img.caption || '') + '" placeholder="Légende (optionnel)" maxlength="255" style="font-size:.78rem">' +
      '<div class="acm-img-actions">' +
        '<label style="display:flex;align-items:center;gap:5px;font-size:.76rem;color:var(--muted-2);cursor:pointer">' +
          '<input type="checkbox" data-toggle="' + img.id + '"' + ((+img.is_active) ? ' checked' : '') + '> Actif' +
        '</label>' +
        '<div style="display:flex;gap:4px">' +
          '<button class="btn btn-sm btn-secondary" type="button" onclick="saveCaption(' + img.id + ')" title="Enregistrer légende"><i class="fa-solid fa-save"></i></button>' +
          '<button class="btn btn-sm btn-danger" type="button" onclick="deleteImage(' + img.id + ')" title="Supprimer"><i class="fa-solid fa-trash"></i></button>' +
        '</div>' +
      '</div>' +
    '</div>';
  bindImgCard(card);
  return card;
}

let dragId = null;

function bindImgCard(card) {
  // toggle is_active
  const tog = card.querySelector('input[data-toggle]');
  if (tog) tog.addEventListener('change', async e => {
    const id = e.target.dataset.toggle;
    const j  = await api('image_update', { id, is_active: e.target.checked ? 1 : 0 });
    if (j.success) {
      card.classList.toggle('off', !e.target.checked);
      toast('Image ' + (e.target.checked ? 'activée' : 'désactivée'));
    }
  });
  // drag & drop reorder
  card.addEventListener('dragstart', () => { dragId = card.dataset.id; card.style.opacity = .4; });
  card.addEventListener('dragend',   () => { card.style.opacity = 1; });
  card.addEventListener('dragover',  e => { e.preventDefault(); });
  card.addEventListener('drop', async e => {
    e.preventDefault();
    if (!dragId || dragId === card.dataset.id || !grid) return;
    const cards = [...grid.querySelectorAll('.acm-img-card')];
    const fromI = cards.findIndex(x => x.dataset.id === dragId);
    const toI   = cards.findIndex(x => x.dataset.id === card.dataset.id);
    const moved = cards[fromI];
    grid.insertBefore(moved, fromI < toI ? card.nextSibling : card);
    const order = [...grid.querySelectorAll('.acm-img-card')].map(x => +x.dataset.id);
    const j = await api('image_reorder', { order: JSON.stringify(order) });
    if (j.success) {
      // Met à jour les badges d'ordre sans reload
      [...grid.querySelectorAll('.acm-img-card')].forEach((c, i) => {
        const b = c.querySelector('.acm-img-badge');
        if (b) b.textContent = '#' + (i + 1);
      });
      toast('Ordre enregistré');
    }
  });
}

if (grid) grid.querySelectorAll('.acm-img-card').forEach(bindImgCard);

// ── Upload fichiers ────────────────────────────────────────────────────────
const drop = document.getElementById('dropZone');
const inp  = document.getElementById('imgInput');
inp.addEventListener('change', e => { upload(e.target.files); inp.value = ''; });
['dragover', 'dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('dragover'); }));
['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('dragover'); }));
drop.addEventListener('drop', e => upload(e.dataTransfer.files));

async function upload(files) {
  if (!files || !files.length) return;
  const zone = document.getElementById('uploadProgress');
  let success = 0, fail = 0;
  for (const f of files) {
    const line = document.createElement('div');
    line.style.cssText = 'padding:8px 12px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;margin-bottom:6px;font-size:.82rem;transition:opacity .4s';
    line.textContent = '⏳ ' + f.name + ' (' + Math.round(f.size / 1024) + ' Ko)';
    zone.appendChild(line);
    const fd = new FormData();
    fd.append('image', f);
    const j = await api('image_upload', fd);
    if (j.success) {
      line.textContent = '✓ ' + f.name;
      line.style.borderColor = '#10b981';
      success++;
      if (j.image && grid) {
        grid.appendChild(buildImgCard(j.image));
        updateSliderCount();
      }
    } else {
      line.textContent = '✗ ' + f.name + ' — ' + (j.error || 'Erreur');
      line.style.borderColor = '#ef4444';
      fail++;
    }
    setTimeout(() => { line.style.opacity = 0; setTimeout(() => line.remove(), 400); }, 3000);
  }
  if (success > 0) toast(success + ' image(s) ajoutée(s)' + (fail ? ' — ' + fail + ' échec(s)' : ''));
}

// ── Ajouter via URL externe ────────────────────────────────────────────────
async function addUrlImage() {
  const url     = document.getElementById('urlInput').value.trim();
  const caption = document.getElementById('urlCaption').value.trim();
  if (!url) { toast('URL vide', true); return; }
  const j = await api('image_add_url', { url, caption });
  if (j.success) {
    toast('Image ajoutée depuis URL');
    document.getElementById('urlInput').value = '';
    document.getElementById('urlCaption').value = '';
    if (j.image && grid) {
      grid.appendChild(buildImgCard(j.image));
      updateSliderCount();
    }
  }
}

// ── Actions partagées (caption save / delete) ──────────────────────────────
async function deleteImage(id) {
  if (!confirm('Supprimer cette image ?')) return;
  const j = await api('image_delete', { id });
  if (j.success) {
    const card = document.querySelector('.acm-img-card[data-id="' + id + '"]');
    if (card) card.remove();
    updateSliderCount();
    toast('Image supprimée');
  }
}
async function saveCaption(id) {
  const cap = document.querySelector('input[data-cap="' + id + '"]').value;
  const j = await api('image_update', { id, caption: cap });
  if (j.success) toast('Légende enregistrée');
}

// ── FAQ ────────────────────────────────────────────────────────────────────
let faqQuill = null;
function ensureFaqQuill() {
  if (faqQuill) return;
  faqQuill = new Quill('#faqEditor', {
    theme: 'snow',
    modules: { toolbar: [['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], ['link'], ['clean']] }
  });
}
function openFaqModal(row) {
  ensureFaqQuill();
  const data = row ? JSON.parse(row.dataset.faq) : null;
  document.getElementById('faqId').value     = data ? data.id : '';
  document.getElementById('faqQ').value      = data ? data.question : '';
  document.getElementById('faqOrder').value  = data ? data.sort_order : 0;
  document.getElementById('faqActive').checked = data ? !!+data.is_active : true;
  document.getElementById('faqTitle').textContent = data ? 'Modifier la question' : 'Nouvelle question';
  faqQuill.root.innerHTML = data ? data.answer : '';
  document.getElementById('faqModal').style.display = 'grid';
}
function closeFaqModal() { document.getElementById('faqModal').style.display = 'none'; }
async function saveFaq() {
  const id = document.getElementById('faqId').value;
  const j = await api('faq_save', {
    id, question: document.getElementById('faqQ').value, answer: faqQuill.root.innerHTML,
    sort_order: document.getElementById('faqOrder').value,
    is_active: document.getElementById('faqActive').checked ? 1 : 0
  });
  if (j.success) { closeFaqModal(); toast('Question enregistrée'); setTimeout(() => location.reload(), 350); }
}
async function deleteFaq(id) {
  if (!confirm('Supprimer cette question ?')) return;
  const j = await api('faq_delete', { id });
  if (j.success) { toast('Question supprimée'); setTimeout(() => location.reload(), 350); }
}
async function toggleFaq(id) {
  const j = await api('faq_toggle', { id });
  if (j.success) { toast('État mis à jour'); setTimeout(() => location.reload(), 350); }
}

// ── Annonces ───────────────────────────────────────────────────────────────
function openAnnModal(row) {
  const d = row ? JSON.parse(row.dataset.ann) : null;
  document.getElementById('annId').value        = d ? d.id : '';
  document.getElementById('annTitleI').value    = d ? d.title : '';
  document.getElementById('annDesc').value      = d ? (d.description || '') : '';
  document.getElementById('annKind').value      = d ? d.kind : 'info';
  document.getElementById('annIcon').value      = d ? (d.icon || '') : 'bullhorn';
  document.getElementById('annOrder').value     = d ? d.sort_order : 0;
  document.getElementById('annLink').value      = d ? (d.link_url || '') : '';
  document.getElementById('annLinkLabel').value = d ? (d.link_label || '') : '';
  document.getElementById('annExp').value       = d && d.expires_at ? d.expires_at.replace(' ', 'T').slice(0, 16) : '';
  document.getElementById('annActive').checked  = d ? !!+d.is_active : true;
  document.getElementById('annTitle').textContent = d ? 'Modifier l\'annonce' : 'Nouvelle annonce';
  document.getElementById('annModal').style.display = 'grid';
}
function closeAnnModal() { document.getElementById('annModal').style.display = 'none'; }
async function saveAnn() {
  const j = await api('ann_save', {
    id: document.getElementById('annId').value,
    title: document.getElementById('annTitleI').value,
    description: document.getElementById('annDesc').value,
    kind: document.getElementById('annKind').value,
    icon: document.getElementById('annIcon').value,
    sort_order: document.getElementById('annOrder').value,
    link_url: document.getElementById('annLink').value,
    link_label: document.getElementById('annLinkLabel').value,
    expires_at: document.getElementById('annExp').value,
    is_active: document.getElementById('annActive').checked ? 1 : 0
  });
  if (j.success) { closeAnnModal(); toast('Annonce enregistrée'); setTimeout(() => location.reload(), 350); }
}
async function deleteAnn(id) {
  if (!confirm('Supprimer cette annonce ?')) return;
  const j = await api('ann_delete', { id });
  if (j.success) { toast('Annonce supprimée'); setTimeout(() => location.reload(), 350); }
}
async function toggleAnn(id) {
  const j = await api('ann_toggle', { id });
  if (j.success) { toast('État mis à jour'); setTimeout(() => location.reload(), 350); }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
