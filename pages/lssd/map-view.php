<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect(SITE_URL . '/pages/lssd/map.php');

try {
    $db = Database::getInstance()->getConnection();
    $st = $db->prepare('SELECT id, title, map_layer, strokes_json, revision FROM lssd_maps WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $row = null;
    error_log('[map-view DB] ' . $e->getMessage());
}
if (!$row) redirect(SITE_URL . '/pages/lssd/map.php');

$pageTitle = (string)$row['title'];

require_once __DIR__ . '/includes/map_api.php';
require_once __DIR__ . '/includes/lssd_map_assets.php';

if (function_exists('mdt_session_refresh_display_name')) {
    mdt_session_refresh_display_name();
}

$csrf     = lssd_map_ensure_csrf();
$layers   = lssd_map_layer_urls();
$layerKey = in_array((string)($row['map_layer'] ?? ''), ['atlas','road','satellite'], true)
    ? (string)$row['map_layer'] : 'atlas';
$imgSrc   = (string)($layers[$layerKey] ?? '');
if ($imgSrc === '') $imgSrc = rtrim(SITE_URL, '/') . '/assets/img_map/Atlas.webp';

$strokes = json_decode((string)($row['strokes_json'] ?? '[]'), true) ?: [];
$strokesJson = json_encode($strokes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($strokesJson === false) $strokesJson = '[]';

$ajaxBase = rtrim(SITE_URL, '/') . '/pages/lssd/ajax/';

/* Nom d'affichage pour la présence */
$displayName = function_exists('mdt_session_display_name') ? mdt_session_display_name() : '';
if (trim($displayName) === '') $displayName = (string)($_SESSION['user_username'] ?? 'Agent');
if (trim($displayName) === '') $displayName = 'Agent';

$mapIdInt    = (int)$row['id'];
$revisionInt = (int)($row['revision'] ?? 0);
$titleEsc    = htmlspecialchars((string)$row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$csrfEsc     = htmlspecialchars((string)$csrf, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$ajaxEsc     = htmlspecialchars((string)$ajaxBase, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$imgSrcEsc   = htmlspecialchars((string)$imgSrc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$userEsc     = htmlspecialchars((string)$displayName, ENT_QUOTES | ENT_HTML5, 'UTF-8');

include __DIR__ . '/includes/header.php';
?>

<!-- Topbar -->
<div style="display:flex;align-items:center;gap:.8rem;margin-bottom:.75rem;flex-wrap:wrap">
    <a href="map.php" style="padding:.35rem .7rem;border-radius:6px;border:1px solid #1e293b;color:#94a3b8;text-decoration:none;font-size:.8rem">&larr; Retour</a>
    <strong style="color:#e2e8f0"><?php echo $titleEsc; ?></strong>

    <!-- Indicateur "en ligne" -->
    <span id="onlineBadge" style="display:none;align-items:center;gap:.4rem;font-size:.72rem;padding:.3rem .65rem;border-radius:6px;border:1px solid rgba(34,197,94,.35);background:rgba(34,197,94,.08);color:#86efac;font-weight:600">
        <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 6px #22c55e;animation:mv-pulse 1.6s ease-in-out infinite"></span>
        <span id="onlineText">1 en ligne</span>
    </span>

    <span id="saveStatus" style="margin-left:auto;font-size:.72rem;color:#64748b;padding:.3rem .7rem;border-radius:6px">Sauvegarde auto</span>
</div>

<style>
@keyframes mv-pulse{0%,100%{opacity:1}50%{opacity:.3}}
.mv-cursor{position:absolute;pointer-events:none;will-change:transform}
.mv-cursor-dot{display:block;width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 8px rgba(0,0,0,.5);position:relative;z-index:2}
.mv-cursor-label{position:absolute;left:18px;top:-20px;white-space:nowrap;background:rgba(3,8,15,.95);border:1px solid #1e293b;padding:2px 7px;border-radius:6px;font-size:.65rem;color:#e2e8f0;font-weight:600;letter-spacing:.01em;box-shadow:0 2px 6px rgba(0,0,0,.4)}

/* ══ Points sur la map ══ */
.mv-point{
    position:absolute;
    left:0;top:0;
    transform:translate(-50%,-100%);
    display:flex;flex-direction:column;align-items:center;
    pointer-events:none;
    user-select:none;-webkit-user-select:none;
    will-change:left,top;
    z-index:1;text-align:center;
}
#mapContainer.mv-tool-point .mv-point{pointer-events:auto;cursor:grab}
#mapContainer.mv-tool-point .mv-point:active{cursor:grabbing}
.mv-point-pin{
    position:relative;
    width:22px;height:22px;
    background:#10b981;
    border:2px solid #fff;
    border-radius:50% 50% 50% 0;
    transform:rotate(-45deg);
    box-shadow:0 3px 8px rgba(0,0,0,.5);
    transition:transform .12s ease;
}
.mv-point-pin::after{
    content:'';
    position:absolute;left:50%;top:50%;
    transform:translate(-50%,-50%);
    width:7px;height:7px;border-radius:50%;
    background:rgba(255,255,255,.85);
}
#mapContainer.mv-tool-point .mv-point:hover .mv-point-pin{transform:rotate(-45deg) scale(1.15)}
.mv-point-label{
    margin-top:5px;max-width:180px;
    padding:3px 8px;
    background:rgba(3,8,15,.92);
    border:1px solid rgba(148,163,184,.2);
    border-radius:6px;color:#e2e8f0;
    font-size:.7rem;font-weight:600;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    box-shadow:0 2px 6px rgba(0,0,0,.4);
}

/* ══ Modales (point + confirmation suppression) ══ */
#pointModal, #pointDeleteModal{
  display:none;position:fixed;inset:0;align-items:center;justify-content:center;
  background:rgba(2,5,12,.72);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
  z-index:9999;padding:1rem;box-sizing:border-box;
}
#pointDeleteModal{ z-index:10050; }
#pointModal.visible, #pointDeleteModal.visible{ display:flex }
.pt-box--confirm{ max-width:400px; text-align:left; padding-top:1.35rem }
.pt-confirm-hd{ display:flex; align-items:flex-start; gap:1rem; margin-bottom:1rem }
.pt-confirm-icon{
  width:48px; height:48px; border-radius:14px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  background:linear-gradient(145deg, rgba(239,68,68,.2) 0%, rgba(220,38,38,.12) 100%);
  border:1px solid rgba(248,113,113,.3);
  color:#f87171; font-size:1.2rem; box-shadow:0 0 0 1px rgba(0,0,0,.2) inset;
}
.pt-confirm-body{ flex:1; min-width:0 }
.pt-confirm-body h3{ color:#f1f5f9; font-size:1.02rem; margin:0 0 .35rem; font-weight:700; line-height:1.25 }
.pt-confirm-text{ color:#94a3b8; font-size:.86rem; line-height:1.55; margin:0 }
.pt-confirm-text strong{ color:#e2e8f0; font-weight:600 }
.pt-actions--confirm{ display:flex; justify-content:flex-end; flex-wrap:wrap; gap:.55rem; margin-top:1.25rem; padding-top:1.1rem; border-top:1px solid rgba(51,65,85,.5) }
.pt-btn--danger-fill{
  background:linear-gradient(180deg, #f87171 0%, #dc2626 100%) !important;
  border:1px solid rgba(0,0,0,.2) !important; color:#fff !important;
  box-shadow:0 2px 8px rgba(220,38,38,.3), 0 1px 0 rgba(255,255,255,.12) inset;
}
.pt-btn--danger-fill:hover:not(:disabled){
  background:linear-gradient(180deg, #fca5a5 0%, #ef4444 100%) !important;
  color:#0f172a !important; box-shadow:0 4px 14px rgba(239,68,68,.35);
}
.pt-btn--danger-fill:focus-visible{ outline:2px solid #f87171; outline-offset:2px }
.pt-box{background:#0f172a;border:1px solid rgba(148,163,184,.25);border-radius:12px;padding:1.5rem;width:90%;max-width:420px;box-shadow:0 20px 40px rgba(0,0,0,.5);animation:pt-in .18s ease-out}
@keyframes pt-in{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}
.pt-box h3{color:#e2e8f0;font-size:1rem;margin:0 0 1rem;font-weight:700}
.pt-label{display:block;font-size:.68rem;color:#94a3b8;margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600}
.pt-input{width:100%;padding:.6rem .8rem;background:#020508;border:1px solid rgba(148,163,184,.2);border-radius:7px;color:#e2e8f0;font-size:.88rem;margin-bottom:1rem;box-sizing:border-box;font-family:inherit;transition:border-color .12s,box-shadow .12s}
.pt-input:focus{outline:none;border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.15)}
.pt-colors{display:flex;gap:6px;margin-bottom:1.25rem;flex-wrap:wrap}
.pt-color{width:28px;height:28px;border-radius:50%;border:2px solid transparent;cursor:pointer;padding:0;transition:transform .12s;box-shadow:inset 0 0 0 1px rgba(255,255,255,.15),0 1px 2px rgba(0,0,0,.3)}
.pt-color:hover{transform:scale(1.12)}
.pt-color.selected{border-color:#fff;transform:scale(1.08);box-shadow:inset 0 0 0 1px rgba(255,255,255,.3),0 0 0 2px rgba(16,185,129,.25)}
.pt-actions{display:flex;justify-content:space-between;gap:.5rem;margin-top:.5rem}
.pt-actions-right{display:flex;gap:.5rem;margin-left:auto}
.pt-btn{padding:.55rem 1.1rem;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .15s;font-family:inherit;display:inline-flex;align-items:center;gap:.4rem}
.pt-btn:disabled{opacity:.5;cursor:not-allowed}
.pt-cancel{background:transparent;border-color:rgba(148,163,184,.2);color:#94a3b8}
.pt-cancel:hover:not(:disabled){background:rgba(255,255,255,.05);color:#e2e8f0}
.pt-save{background:rgba(16,185,129,.2);border-color:rgba(16,185,129,.5);color:#34d399}
.pt-save:hover:not(:disabled){background:rgba(16,185,129,.35);color:#fff}
.pt-delete{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35);color:#fca5a5}
.pt-delete:hover:not(:disabled){background:rgba(239,68,68,.3);color:#fff}
.pt-err{color:#ef4444;font-size:.75rem;margin-top:-.5rem;margin-bottom:.75rem;display:none}
.pt-err.visible{display:block}

/* ══ Palette d’outils (tracés) — design condensé ══ */
.mv-tb{
  position:absolute;top:12px;left:12px;z-index:10;max-width:min(100% - 24px, 320px);
  display:flex;flex-direction:column;gap:0;
  background:linear-gradient(160deg, rgba(15,23,42,.97) 0%, rgba(3,8,15,.94) 100%);
  border:1px solid rgba(100,116,139,.32);
  border-radius:14px;
  box-shadow:0 8px 32px rgba(0,0,0,.45), 0 0 0 1px rgba(255,255,255,.04) inset, 0 1px 0 rgba(255,255,255,.06) inset;
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  padding:.65rem .75rem .7rem;
  color:#e2e8f0;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  user-select:none;
}
.mv-tb-head{
  display:flex;align-items:center;justify-content:space-between;gap:.5rem;
  margin-bottom:.55rem;padding-bottom:.45rem;
  border-bottom:1px solid rgba(51,65,85,.65);
}
.mv-tb-title{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#64748b}
.mv-tb-legend{font-size:.58rem;color:#475569;font-weight:500}
.mv-tb-grid{display:grid;grid-template-columns:1fr;gap:.55rem}
@media (min-width: 520px){
  .mv-tb{max-width:300px}
}
.mv-tb-block{}
.mv-tb-sec{
  display:flex;align-items:center;gap:.4rem;
  font-size:.58rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#64748b;
  margin-bottom:.35rem;
}
.mv-tb-sec i{margin-right:2px;opacity:.9;color:#10b981;font-size:.65rem}
.mv-tb-toolstrip{
  display:grid;grid-template-columns:repeat(3, 1fr);gap:6px;
}
.mv-tb-navstrip{
  display:grid;grid-template-columns:repeat(2, 1fr);gap:6px;
  margin-top:.1rem;
}
.mv-tb-btn{
  position:relative;
  display:flex;align-items:center;justify-content:center;
  min-height:42px;border-radius:11px;
  border:1px solid rgba(51,65,85,.9);
  background:linear-gradient(180deg, rgba(30,41,59,.65) 0%, rgba(15,23,42,.85) 100%);
  color:#94a3b8;
  cursor:pointer;transition:transform .14s, border-color .14s, box-shadow .14s, color .14s, background .14s;
  font-size:1.05rem;line-height:1;
  box-shadow:0 1px 0 rgba(255,255,255,.04) inset;
}
.mv-tb-btn::after{
  content:'';position:absolute;inset:0;border-radius:10px;pointer-events:none;
  box-shadow:0 0 0 0 rgba(16,185,129,0);
  transition:box-shadow .2s
}
.mv-tb-btn:hover{
  color:#e2e8f0;
  border-color:rgba(100,116,139,.5);
  background:linear-gradient(180deg, rgba(51,65,85,.5) 0%, rgba(30,41,59,.9) 100%);
  transform:translateY(-1px);
}
.mv-tb-btn:active{transform:translateY(0)}
.mv-tb-btn.is-active{
  color:#f0f9ff;
  border-color:rgba(16,185,129,.65);
  background:linear-gradient(180deg, rgba(16,185,129,.35) 0%, rgba(4,120,87,.22) 100%);
  box-shadow:0 0 0 1px rgba(16,185,129,.25) inset, 0 2px 12px rgba(16,185,129,.2);
}
.mv-tb-btn.is-active::after{box-shadow:0 0 0 1px rgba(16,185,129,.2)}
.mv-tb-btn[title] { touch-action: manipulation; }
.mv-tb-palette{
  display:grid;
  grid-template-columns:repeat(4, 1fr);
  gap:8px;
  padding:2px 0 4px;
}
.mv-swatch{
  position:relative;
  width:100%;aspect-ratio:1;max-width:40px;max-height:40px;margin:0 auto;
  border-radius:50%;
  border:2px solid rgba(15,23,42,.85);
  cursor:pointer;padding:0;
  box-shadow:
    0 2px 4px rgba(0,0,0,.4),
    inset 0 2px 4px rgba(255,255,255,.2),
    inset 0 -2px 3px rgba(0,0,0,.2);
  transition:transform .15s, box-shadow .15s, border-color .15s;
  outline:none;
}
.mv-swatch::before{
  content:'';position:absolute;inset:3px;border-radius:50%;
  background:inherit;
  opacity:.35;filter:blur(4px);z-index:0
}
.mv-swatch:hover{transform:scale(1.08);box-shadow:0 2px 8px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.35)}
.mv-swatch.is-selected{
  border-color:#fff;
  box-shadow:0 0 0 2px rgba(16,185,129,.55), 0 2px 10px rgba(16,185,129,.35), inset 0 2px 4px rgba(255,255,255,.25);
  transform:scale(1.04);
  z-index:1;
}
.mv-swatch.is-selected::after{
  content:'';position:absolute;inset:-4px;border-radius:50%;
  box-shadow:0 0 0 1px rgba(255,255,255,.2);pointer-events:none
}
/* Blanc lisible */
.mv-swatch[data-c="#f9fafb"]{box-shadow:0 2px 4px rgba(0,0,0,.3), inset 0 0 0 1px rgba(0,0,0,.12)}
.mv-tb-size{
  display:flex;flex-direction:column;gap:.35rem;
  padding:4px 0 2px;
}
.mv-tb-size-head{
  display:flex;align-items:baseline;justify-content:space-between;gap:.5rem;
  font-size:.55rem;letter-spacing:.05em;font-weight:700;
  text-transform:uppercase;color:#64748b
}
#widthValueDisplay{
  font-family:'IBM Plex Mono',ui-monospace,monospace;
  font-size:.7rem;font-weight:700;letter-spacing:0;
  color:#6ee7b7;padding:2px 7px;border-radius:6px;
  background:rgba(16,185,129,.2);border:1px solid rgba(16,185,129,.35);
}
.mv-tb input[type=range].mv-tb-range{
  -webkit-appearance:none;appearance:none;width:100%;
  height:8px;border-radius:99px;cursor:pointer;
  background:linear-gradient(90deg, rgba(51,65,85,.9) 0%, rgba(30,64,175,.4) 100%);
  box-shadow:0 1px 0 rgba(255,255,255,.04) inset;
  border:1px solid rgba(30,41,59,.8);
  margin:4px 0 2px;
}
.mv-tb input[type=range].mv-tb-range::-webkit-slider-thumb{
  -webkit-appearance:none;appearance:none;
  width:18px;height:18px;border-radius:50%;
  background:linear-gradient(180deg, #e2e8f0 0%, #94a3b8 100%);
  border:2px solid #0f172a;
  box-shadow:0 1px 3px rgba(0,0,0,.4),0 0 0 1px rgba(16,185,129,.3);
  cursor:grab;transition:transform .12s
}
.mv-tb input[type=range].mv-tb-range::-webkit-slider-thumb:active{cursor:grabbing;transform:scale(1.06)}
.mv-tb input[type=range].mv-tb-range::-moz-range-thumb{
  width:16px;height:16px;border-radius:50%;
  background:linear-gradient(180deg, #e2e8f0 0%, #94a3b8 100%);
  border:2px solid #0f172a;cursor:grab
}
.mv-tb hr.mv-tb-rule{
  border:0;height:1px;margin:.35rem 0;
  background:linear-gradient(90deg, transparent, rgba(51,65,85,.8), transparent);
}
.mv-tb .mv-tb-pill{
  display:inline-block;font-size:.5rem;padding:1px 5px;border-radius:4px;
  background:rgba(15,23,42,.6);color:#64748b;font-weight:600;letter-spacing:.04em
}
</style>

<!-- Container carte -->
<div id="mapContainer"
     data-map-id="<?php echo $mapIdInt; ?>"
     data-csrf="<?php echo $csrfEsc; ?>"
     data-ajax="<?php echo $ajaxEsc; ?>"
     data-revision="<?php echo $revisionInt; ?>"
     data-user="<?php echo $userEsc; ?>"
     style="position:relative;width:100%;height:600px;border:4px solid #ef4444;border-radius:8px;overflow:hidden;background:#0a1020;cursor:crosshair;touch-action:none;user-select:none">

    <div id="mapPlane" style="position:absolute;left:0;top:0;transform-origin:0 0;background:#0a1020;width:1px;height:1px">
        <img id="mapImg" src="<?php echo $imgSrcEsc; ?>" alt="Carte"
             style="display:block;width:100%;height:100%;object-fit:fill;pointer-events:none;user-select:none;-webkit-user-drag:none"
             draggable="false">
    </div>

    <canvas id="mapCanvas" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:3"></canvas>

    <!-- Overlay points (entre canvas et presence) -->
    <div id="pointsOverlay" style="position:absolute;inset:0;pointer-events:none;z-index:5"></div>

    <!-- Overlay pour les curseurs des autres utilisateurs -->
    <div id="presenceOverlay" style="position:absolute;inset:0;pointer-events:none;z-index:510"></div>

    <script type="application/json" id="mapStrokesData"><?php echo $strokesJson; ?></script>

    <div class="mv-tb" id="mapDrawToolbar" role="toolbar" aria-label="Outils de dessin et navigation">
        <div class="mv-tb-head">
            <div>
                <div class="mv-tb-title">Dessin</div>
                <div class="mv-tb-legend" style="margin-top:2px">Pinceau S · Marqueur M · Gomme G &nbsp;·&nbsp; Points P · Pan N</div>
            </div>
        </div>
        <div class="mv-tb-grid">
            <div class="mv-tb-block">
                <div class="mv-tb-sec"><i class="fas fa-pen-fancy" aria-hidden="true"></i> Outils de tracé</div>
                <div class="mv-tb-toolstrip">
                    <button type="button" class="mv-tb-btn is-active" id="toolPen" title="Pinceau (S)">
                        <i class="fas fa-paintbrush" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="mv-tb-btn" id="toolMarker" title="Marqueur / surligneur (M)">
                        <i class="fas fa-highlighter" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="mv-tb-btn" id="toolEraser" title="Gomme (G)">
                        <i class="fas fa-eraser" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="mv-tb-block">
                <div class="mv-tb-sec"><i class="fas fa-palette" aria-hidden="true"></i> Couleur</div>
                <div class="mv-tb-palette" id="mapColorPalette" role="group" aria-label="Palette de couleurs">
                    <button type="button" class="color-btn mv-swatch is-selected" data-c="#ef4444" style="background:#ef4444" title="Rouge"></button>
                    <button type="button" class="color-btn mv-swatch" data-c="#f97316" style="background:#f97316" title="Orange"></button>
                    <button type="button" class="color-btn mv-swatch" data-c="#eab308" style="background:#eab308" title="Jaune"></button>
                    <button type="button" class="color-btn mv-swatch" data-c="#22c55e" style="background:#22c55e" title="Vert"></button>
                    <button type="button" class="color-btn mv-swatch" data-c="#10b981" style="background:#10b981" title="Bleu"></button>
                    <button type="button" class="color-btn mv-swatch" data-c="#a855f7" style="background:#a855f7" title="Violet"></button>
                    <button type="button" class="color-btn mv-swatch" data-c="#ec4899" style="background:#ec4899" title="Rose"></button>
                    <button type="button" class="color-btn mv-swatch" data-c="#f9fafb" style="background:#f9fafb" title="Blanc"></button>
                </div>
            </div>
            <div class="mv-tb-block">
                <div class="mv-tb-size">
                    <div class="mv-tb-size-head">
                        <span><i class="fas fa-circle-half-stroke" style="font-size:.55rem;opacity:.7;margin-right:3px" aria-hidden="true"></i> Taille</span>
                        <span id="widthValueDisplay">3 px</span>
                    </div>
                    <input type="range" class="mv-tb-range" id="widthSlider" min="1" max="32" value="3" step="1" aria-valuemin="1" aria-valuemax="32" aria-label="Épaisseur du pinceau">
                </div>
            </div>
        </div>
        <hr class="mv-tb-rule" />
        <div class="mv-tb-block" style="margin-top:0">
            <div class="mv-tb-sec" style="margin-top:0">
                <i class="fas fa-compass" aria-hidden="true"></i> Plan <span class="mv-tb-pill">P · N</span>
            </div>
            <div class="mv-tb-navstrip">
                <button type="button" class="mv-tb-btn" id="toolPoint" title="Placer / déplacer des points (P)">
                    <i class="fas fa-map-pin" aria-hidden="true"></i>
                </button>
                <button type="button" class="mv-tb-btn" id="toolNav" title="Déplacer la carte (N)">
                    <i class="fas fa-up-down-left-right" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>

    <div style="position:absolute;right:12px;bottom:12px;z-index:10;display:flex;flex-direction:column;gap:5px">
        <button type="button" id="zoomIn" style="width:38px;height:38px;border-radius:8px;border:1px solid #1e293b;background:rgba(3,8,15,.92);color:#e2e8f0;cursor:pointer;font-size:1rem">+</button>
        <button type="button" id="zoomOut" style="width:38px;height:38px;border-radius:8px;border:1px solid #1e293b;background:rgba(3,8,15,.92);color:#e2e8f0;cursor:pointer;font-size:1rem">&minus;</button>
    </div>

</div>

<!-- ══ Modal point ══ -->
<div id="pointModal">
    <div class="pt-box">
        <h3 id="ptTitle">&#128205; Nouveau point</h3>
        <label class="pt-label" for="ptInput">Titre</label>
        <input type="text" id="ptInput" class="pt-input" placeholder="Ex : Point de RDV, Objectif, Zone de garde..." maxlength="255" autocomplete="off">
        <label class="pt-label">Couleur</label>
        <div class="pt-colors" id="ptColors">
            <button type="button" class="pt-color" data-c="#ef4444" style="background:#ef4444" title="Rouge"></button>
            <button type="button" class="pt-color" data-c="#f97316" style="background:#f97316" title="Orange"></button>
            <button type="button" class="pt-color" data-c="#eab308" style="background:#eab308" title="Jaune"></button>
            <button type="button" class="pt-color" data-c="#22c55e" style="background:#22c55e" title="Vert"></button>
            <button type="button" class="pt-color selected" data-c="#10b981" style="background:#10b981" title="Bleu"></button>
            <button type="button" class="pt-color" data-c="#a855f7" style="background:#a855f7" title="Violet"></button>
            <button type="button" class="pt-color" data-c="#f9fafb" style="background:#f9fafb" title="Blanc"></button>
        </div>
        <div id="ptErr" class="pt-err"></div>
        <div class="pt-actions">
            <button type="button" id="ptDelete" class="pt-btn pt-delete" style="display:none">&#128465; Supprimer</button>
            <div class="pt-actions-right">
                <button type="button" id="ptCancel" class="pt-btn pt-cancel">Annuler</button>
                <button type="button" id="ptSave"   class="pt-btn pt-save">&#10003; Enregistrer</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ Confirmation suppression d’un point (marqueur) ══ -->
<div id="pointDeleteModal" role="dialog" aria-modal="true" aria-labelledby="ptDelTitle" aria-describedby="ptDelDesc">
    <div class="pt-box pt-box--confirm">
        <div class="pt-confirm-hd">
            <div class="pt-confirm-icon" aria-hidden="true"><i class="fas fa-trash-can"></i></div>
            <div class="pt-confirm-body">
                <h3 id="ptDelTitle">Supprimer ce point ?</h3>
                <p class="pt-confirm-text" id="ptDelDesc">Cette action est <strong>définitive</strong>. Le marqueur sera retiré de la carte pour <strong>tous</strong> les opérateurs. Vous ne pourrez pas l’annuler.</p>
            </div>
        </div>
        <div class="pt-actions pt-actions--confirm">
            <button type="button" id="ptDelCancel" class="pt-btn pt-cancel">Conserver le point</button>
            <button type="button" id="ptDelConfirm" class="pt-btn pt-btn--danger-fill">
                <i class="fas fa-trash" style="font-size:.85em;margin-right:.4rem" aria-hidden="true"></i>Supprimer
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var container = document.getElementById('mapContainer');
    var plane     = document.getElementById('mapPlane');
    var img       = document.getElementById('mapImg');
    var canvas    = document.getElementById('mapCanvas');
    var presenceOverlay = document.getElementById('presenceOverlay');
    var pointsOverlay   = document.getElementById('pointsOverlay');

    if (!container || !plane || !img || !canvas) return;

    var ctx = canvas.getContext('2d');
    var MAP_ID       = parseInt(container.dataset.mapId, 10) || 0;
    var CSRF         = container.dataset.csrf || '';
    var AJAX         = container.dataset.ajax || '';
    var DISPLAY_NAME = container.dataset.user || 'Agent';
    var revision     = parseInt(container.dataset.revision, 10) || 0;

    /* strokes contient MIXTE : tracés (avec points[]) ET points (avec type==='point') */
    var strokes = [];
    try {
        var el = document.getElementById('mapStrokesData');
        if (el && el.textContent) strokes = JSON.parse(el.textContent) || [];
    } catch (e) {}

    var mapW = 1, mapH = 1, sc = 1, tx = 0, ty = 0;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    var tool = 'pen', color = '#ef4444', lineWidth = 3;
    var brushActive = true;
    var pointMode = false; /* NEW : outil "point" actif */
    var drawing = false, panning = false, panStart = null, cur = null;
    var lastNorm = { nx: 0.5, ny: 0.5 };
    var presenceList = [];

    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function escapeHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function vpSize() {
        return { w: Math.max(container.clientWidth || 800, 50), h: Math.max(container.clientHeight || 600, 50) };
    }
    function applyTransform() {
        plane.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + sc + ')';
        renderPresence();
        renderPoints(); /* NEW : replacer les points à chaque pan/zoom */
    }
    function resizeCanvas() {
        var sz = vpSize();
        var pw = Math.max(1, Math.round(sz.w * dpr));
        var ph = Math.max(1, Math.round(sz.h * dpr));
        if (canvas.width !== pw || canvas.height !== ph) { canvas.width = pw; canvas.height = ph; }
    }
    function fitView() {
        if (mapW < 2 || mapH < 2) return;
        var sz = vpSize();
        sc = Math.min(sz.w / mapW, sz.h / mapH) * 0.97;
        tx = (sz.w - mapW * sc) / 2;
        ty = (sz.h - mapH * sc) / 2;
        applyTransform(); resizeCanvas(); redraw();
    }
    function drawStroke(s) {
        /* Skip si c'est un point (pas de points[]) */
        var pts = s.points;
        if (!pts || pts.length < 2) return;
        ctx.beginPath(); ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        var baseW = Math.max(1, (s.width || 3) * sc * dpr);
        if (s.tool === 'eraser') {
            ctx.globalCompositeOperation = 'destination-out';
            ctx.globalAlpha = 1;
            ctx.lineWidth = baseW;
            ctx.strokeStyle = 'rgba(0,0,0,1)';
        } else if (s.tool === 'marker') {
            ctx.globalCompositeOperation = 'source-over';
            ctx.lineWidth = baseW * 1.5;
            ctx.globalAlpha = 0.4;
            ctx.strokeStyle = s.color || '#facc15';
        } else {
            ctx.globalCompositeOperation = 'source-over';
            ctx.lineWidth = baseW;
            ctx.globalAlpha = 1;
            ctx.strokeStyle = s.color || '#fff';
        }
        ctx.moveTo((tx + pts[0][0] * mapW * sc) * dpr, (ty + pts[0][1] * mapH * sc) * dpr);
        for (var i = 1; i < pts.length; i++)
            ctx.lineTo((tx + pts[i][0] * mapW * sc) * dpr, (ty + pts[i][1] * mapH * sc) * dpr);
        ctx.stroke();
        ctx.globalAlpha = 1;
    }
    var rafId = null;
    function redraw() {
        if (rafId) cancelAnimationFrame(rafId);
        rafId = requestAnimationFrame(function () {
            rafId = null;
            if (!canvas.width) resizeCanvas();
            if (!canvas.width) return;
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            for (var i = 0; i < strokes.length; i++) drawStroke(strokes[i]);
            if (cur && cur.points && cur.points.length > 1) drawStroke(cur);
            ctx.globalCompositeOperation = 'source-over';
        });
    }
    function onReady() {
        mapW = img.naturalWidth | 0; mapH = img.naturalHeight | 0;
        if (mapW < 2 || mapH < 2) return;
        plane.style.width = mapW + 'px'; plane.style.height = mapH + 'px';
        fitView();
    }
    if (img.complete && img.naturalWidth > 0) onReady();
    else {
        img.addEventListener('load', onReady);
        img.addEventListener('error', function () {
            container.insertAdjacentHTML('beforeend',
                '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#ef4444;z-index:99;font-family:monospace;text-align:center;padding:2rem"><div>&#10060; Image introuvable<br><small>' + img.src + '</small></div></div>');
        });
    }

    function worldFromClient(cx, cy) {
        var r = container.getBoundingClientRect();
        return { wx: (cx - r.left - tx) / sc, wy: (cy - r.top - ty) / sc, mx: cx - r.left, my: cy - r.top };
    }

    /* ═══════════════════════════════════════
       NEW : RENDU DES POINTS (DOM overlay)
       ═══════════════════════════════════════ */
    function renderPoints() {
        if (!pointsOverlay) return;
        pointsOverlay.innerHTML = '';
        for (var i = 0; i < strokes.length; i++) {
            var p = strokes[i];
            if (!p || p.type !== 'point') continue;
            if (typeof p.x !== 'number' || typeof p.y !== 'number') continue;
            var x = tx + p.x * mapW * sc;
            var y = ty + p.y * mapH * sc;
            var el = document.createElement('div');
            el.className = 'mv-point';
            el.dataset.id = p.id || '';
            el.style.left = x + 'px';
            el.style.top  = y + 'px';
            el.title = (p.author ? (p.author + ' — ') : '') + (p.title || '');
            el.innerHTML =
                '<span class="mv-point-pin" style="background:' + (p.color || '#10b981') + '"></span>' +
                '<span class="mv-point-label">' + escapeHtml(p.title || '') + '</span>';
            bindPointHandlers(el, p);
            pointsOverlay.appendChild(el);
        }
    }

    function bindPointHandlers(el, p) {
        el.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            e.stopPropagation();
            e.preventDefault();
            var startX = e.clientX, startY = e.clientY;
            var moved = false;
            var origX = p.x, origY = p.y;

            function onMove(evt) {
                var dx = evt.clientX - startX, dy = evt.clientY - startY;
                if (!moved && Math.abs(dx) + Math.abs(dy) > 4) moved = true;
                if (moved && pointMode) {
                    var r = container.getBoundingClientRect();
                    var mx = evt.clientX - r.left, my = evt.clientY - r.top;
                    p.x = clamp((mx - tx) / sc / mapW, 0, 1);
                    p.y = clamp((my - ty) / sc / mapH, 0, 1);
                    renderPoints();
                }
            }
            function onUp(evt) {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                if (!moved) {
                    /* Click pur : ouvrir modal d'édition */
                    openPointModal(p);
                } else if (pointMode) {
                    /* Drag terminé : sauvegarder la nouvelle position */
                    savePointRemote(p).catch(function () {
                        p.x = origX; p.y = origY;
                        renderPoints();
                        setSave('err', '\u26a0 Erreur sync');
                        setTimeout(function () { setSave('', 'Sauvegarde auto'); }, 3000);
                    });
                }
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    }

    /* ═══════════════════════════════════════
       NEW : MODAL POINT
       ═══════════════════════════════════════ */
    var modal      = document.getElementById('pointModal');
    var modalTitle = document.getElementById('ptTitle');
    var inputEl    = document.getElementById('ptInput');
    var colorsEl   = document.getElementById('ptColors');
    var btnCancel  = document.getElementById('ptCancel');
    var btnSave    = document.getElementById('ptSave');
    var btnDelete  = document.getElementById('ptDelete');
    var errEl      = document.getElementById('ptErr');

    var delModal   = document.getElementById('pointDeleteModal');
    var delCancel  = document.getElementById('ptDelCancel');
    var delConfirm = document.getElementById('ptDelConfirm');

    var modalState = { editingId: null, x: 0, y: 0, color: '#10b981' };

    function setModalColor(c) {
        modalState.color = c;
        var btns = colorsEl.querySelectorAll('.pt-color');
        for (var i = 0; i < btns.length; i++) {
            btns[i].classList.toggle('selected', btns[i].dataset.c === c);
        }
    }
    colorsEl.querySelectorAll('.pt-color').forEach(function (b) {
        b.addEventListener('click', function () { setModalColor(b.dataset.c); });
    });
    function showErr(msg) {
        if (!errEl) return;
        errEl.textContent = msg;
        errEl.classList.add('visible');
    }
    function hideErr() {
        if (!errEl) return;
        errEl.classList.remove('visible');
    }

    function openPointModal(pOrNull) {
        hideErr();
        if (pOrNull && pOrNull.id) {
            modalState.editingId = pOrNull.id;
            modalState.x = pOrNull.x;
            modalState.y = pOrNull.y;
            modalTitle.innerHTML = '&#128205; Modifier le point';
            inputEl.value = pOrNull.title || '';
            setModalColor(pOrNull.color || '#10b981');
            btnDelete.style.display = 'inline-flex';
        } else {
            /* Création : modalState.x/y déjà préparés avant l'appel */
            modalState.editingId = null;
            modalTitle.innerHTML = '&#128205; Nouveau point';
            inputEl.value = '';
            setModalColor('#10b981');
            btnDelete.style.display = 'none';
        }
        modal.classList.add('visible');
        setTimeout(function () { inputEl.focus(); inputEl.select(); }, 50);
    }

    function closePointModal() {
        if (delModal) delModal.classList.remove('visible');
        modal.classList.remove('visible');
        modalState.editingId = null;
        hideErr();
    }

    function openPointDeleteModal() {
        if (!delModal || !modalState.editingId) return;
        delModal.classList.add('visible');
        setTimeout(function () { if (delCancel) delCancel.focus(); }, 30);
    }
    function closePointDeleteModal() {
        if (delModal) delModal.classList.remove('visible');
    }

    btnCancel.addEventListener('click', closePointModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closePointModal();
    });
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); btnSave.click(); }
    });

    btnSave.addEventListener('click', function () {
        hideErr();
        var title = (inputEl.value || '').trim();
        if (title.length < 1)   { showErr('Titre requis'); return; }
        if (title.length > 255) { showErr('Titre trop long (max 255)'); return; }

        btnSave.disabled = true;
        btnCancel.disabled = true;
        btnDelete.disabled = true;

        var p = {
            id:     modalState.editingId || ('p_' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36)),
            type:   'point',
            x:      modalState.x,
            y:      modalState.y,
            title:  title,
            color:  modalState.color,
            author: DISPLAY_NAME,
        };

        savePointRemote(p)
            .then(function () {
                closePointModal();
                btnSave.disabled = false;
                btnCancel.disabled = false;
                btnDelete.disabled = false;
            })
            .catch(function (err) {
                showErr(err.message || 'Erreur');
                btnSave.disabled = false;
                btnCancel.disabled = false;
                btnDelete.disabled = false;
            });
    });

    btnDelete.addEventListener('click', function () {
        if (!modalState.editingId) return;
        openPointDeleteModal();
    });

    if (delCancel) delCancel.addEventListener('click', function () { closePointDeleteModal(); });
    if (delModal) delModal.addEventListener('click', function (e) {
        if (e.target === delModal) closePointDeleteModal();
    });

    if (delConfirm) delConfirm.addEventListener('click', function () {
        if (!modalState.editingId) { closePointDeleteModal(); return; }
        var idToDelete = modalState.editingId;

        delConfirm.disabled = true;
        if (delCancel) delCancel.disabled = true;
        btnSave.disabled = true;
        btnCancel.disabled = true;
        btnDelete.disabled = true;

        deletePointRemote(idToDelete)
            .then(function () {
                closePointDeleteModal();
                closePointModal();
                delConfirm.disabled = false;
                if (delCancel) delCancel.disabled = false;
                btnSave.disabled = false;
                btnCancel.disabled = false;
                btnDelete.disabled = false;
            })
            .catch(function (err) {
                closePointDeleteModal();
                showErr(err.message || 'Erreur');
                delConfirm.disabled = false;
                if (delCancel) delCancel.disabled = false;
                btnSave.disabled = false;
                btnCancel.disabled = false;
                btnDelete.disabled = false;
            });
    });

    /* ═══════════════════════════════════════
       NEW : API POINTS
       ═══════════════════════════════════════ */
    function savePointRemote(p) {
        var prevRev = revision;
        setSave('saving', '\u27f3 Sauvegarde\u2026');
        return fetch(AJAX + 'map_point_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ map_id: MAP_ID, point: p, csrf: CSRF }),
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.ok) throw new Error(j.error || 'erreur');
            /* Mise à jour locale */
            var idx = -1;
            for (var i = 0; i < strokes.length; i++) {
                if (strokes[i] && strokes[i].id === j.point.id) { idx = i; break; }
            }
            if (idx >= 0) strokes[idx] = j.point;
            else strokes.push(j.point);
            renderPoints();

            var newRev = parseInt(j.revision, 10) || 0;
            if (newRev > prevRev + 1) pollNow();
            else revision = newRev;

            flashSaved();
            return j;
        });
    }

    function deletePointRemote(pointId) {
        var prevRev = revision;
        setSave('saving', '\u27f3 Sauvegarde\u2026');
        return fetch(AJAX + 'map_point_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ map_id: MAP_ID, point_id: pointId, csrf: CSRF }),
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.ok) throw new Error(j.error || 'erreur');
            strokes = strokes.filter(function (x) { return !x || x.id !== pointId; });
            renderPoints();

            var newRev = parseInt(j.revision, 10) || 0;
            if (newRev > prevRev + 1) pollNow();
            else revision = newRev;

            flashSaved();
            return j;
        });
    }

    /* ─────────────────────────────────────────
       INDICATEURS (sauvegarde + en ligne)
       ───────────────────────────────────────── */
    var saveEl = document.getElementById('saveStatus');
    var saveTimer;
    function setSave(st, msg) {
        if (!saveEl) return;
        var cls = {
            saving: ['rgba(249,115,22,.1)', 'rgba(249,115,22,.4)', '#fdba74'],
            saved:  ['rgba(34,197,94,.1)', 'rgba(34,197,94,.4)', '#86efac'],
            err:    ['rgba(239,68,68,.1)', 'rgba(239,68,68,.4)', '#fca5a5'],
            '':     ['transparent', 'transparent', '#64748b'],
        }[st] || ['transparent', 'transparent', '#64748b'];
        saveEl.style.background = cls[0];
        saveEl.style.border = '1px solid ' + cls[1];
        saveEl.style.color = cls[2];
        saveEl.textContent = msg;
    }
    function flashSaved() {
        setSave('saved', '\u2713 Sauvegard\u00e9');
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () { setSave('', 'Sauvegarde auto'); }, 2500);
    }

    var onlineBadge = document.getElementById('onlineBadge');
    var onlineText  = document.getElementById('onlineText');
    function updateOnlineBadge(count) {
        if (!onlineBadge || !onlineText) return;
        if (count > 1) {
            onlineBadge.style.display = 'inline-flex';
            onlineText.textContent = count + ' en ligne';
        } else {
            onlineBadge.style.display = 'none';
        }
    }

    /* ─────────────────────────────────────────
       ENVOI D'UN TRACÉ AU SERVEUR
       ───────────────────────────────────────── */
    function pushStroke(s) {
        if (!MAP_ID || !AJAX) return Promise.reject(new Error('config'));
        var prevRev = revision;
        setSave('saving', '\u27f3 Sauvegarde\u2026');
        return fetch(AJAX + 'map_append_stroke.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ map_id: MAP_ID, stroke: s, csrf: CSRF }),
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.ok) throw new Error(j.error || 'erreur');
            var newRev = parseInt(j.revision, 10) || 0;
            if (newRev > prevRev + 1) {
                pollNow();
            } else {
                revision = newRev;
            }
            flashSaved();
            return j;
        });
    }

    /* ─────────────────────────────────────────
       COLLABORATION : POLLING DES TRACÉS
       ───────────────────────────────────────── */
    var pollTimer = null;
    var polling = false;

    function pollNow() {
        if (polling) return;
        polling = true;
        fetch(AJAX + 'map_poll.php?id=' + encodeURIComponent(MAP_ID) + '&_=' + Date.now(), {
            credentials: 'same-origin',
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.ok) return;
            var newRev = parseInt(j.revision, 10) || 0;
            if (newRev > revision) {
                revision = newRev;
                strokes = Array.isArray(j.strokes) ? j.strokes : [];
                redraw();
                renderPoints(); /* NEW : re-rendre les points après poll */
            }
            presenceList = Array.isArray(j.presence) ? j.presence : [];
            renderPresence();
            updateOnlineBadge(presenceList.length);
        }).catch(function () {}).then(function () {
            polling = false;
        });
    }

    function pollMs() {
        return document.hidden ? 3000 : 1000;
    }
    function armPoll() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollNow, pollMs());
    }

    /* ─────────────────────────────────────────
       COLLABORATION : ENVOI DE LA PRÉSENCE
       ───────────────────────────────────────── */
    var presenceTimer = null;
    function sendPresence() {
        if (!MAP_ID || !AJAX) return;
        fetch(AJAX + 'map_presence.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                map_id: MAP_ID,
                lat: lastNorm.nx,
                lng: lastNorm.ny,
                display_name: DISPLAY_NAME,
                csrf: CSRF,
            }),
        }).catch(function () {});
    }
    function presenceMs() {
        return document.hidden ? 2500 : 800;
    }
    function armPresence() {
        if (presenceTimer) clearInterval(presenceTimer);
        presenceTimer = setInterval(sendPresence, presenceMs());
    }

    /* ─────────────────────────────────────────
       COLLABORATION : AFFICHAGE DES CURSEURS
       ───────────────────────────────────────── */
    function hueFor(uid) {
        var n = parseInt(uid, 10) || 0;
        return (n * 47 + (n % 13) * 31) % 360;
    }
    function renderPresence() {
        if (!presenceOverlay) return;
        presenceOverlay.innerHTML = '';
        presenceList.forEach(function (p) {
            if (p.self) return;
            var nx = parseFloat(p.lat), ny = parseFloat(p.lng);
            if (isNaN(nx) || isNaN(ny)) return;
            var x = tx + nx * mapW * sc;
            var y = ty + ny * mapH * sc;
            var hue = hueFor(p.user_id);
            var el = document.createElement('div');
            el.className = 'mv-cursor';
            el.style.transform = 'translate(' + (x - 7) + 'px,' + (y - 7) + 'px)';
            el.innerHTML =
                '<span class="mv-cursor-dot" style="background:hsl(' + hue + ',72%,52%)"></span>' +
                '<span class="mv-cursor-label" style="color:hsl(' + hue + ',72%,75%)">' + escapeHtml(p.display_name || 'Agent') + '</span>';
            presenceOverlay.appendChild(el);
        });
    }

    /* ─────────────────────────────────────────
       ÉVÈNEMENTS SOURIS
       ───────────────────────────────────────── */
    container.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    container.addEventListener('dragstart',   function (e) { e.preventDefault(); }, true);
    container.addEventListener('wheel', function (e) {
        e.preventDefault();
        var w = worldFromClient(e.clientX, e.clientY);
        var s2 = clamp(sc * (e.deltaY > 0 ? 0.92 : 1.08), 0.02, 32);
        tx = w.mx - w.wx * s2; ty = w.my - w.wy * s2; sc = s2;
        applyTransform(); redraw();
    }, { passive: false });

    container.addEventListener('mousedown', function (e) {
        if (e.target.closest('button') || e.target.closest('input')) return;
        if (e.target.closest('.mv-point')) return; /* NEW : clic géré par le point lui-même */
        if (e.button === 2) { e.preventDefault(); panning = true; panStart = { x: e.clientX, y: e.clientY, tx: tx, ty: ty }; return; }
        if (e.button !== 0) return;

        /* NEW : mode point → ouvrir modal création */
        if (pointMode) {
            e.preventDefault();
            var u = worldFromClient(e.clientX, e.clientY);
            modalState.x = clamp(u.wx / mapW, 0, 1);
            modalState.y = clamp(u.wy / mapH, 0, 1);
            openPointModal(null);
            return;
        }

        if (!brushActive) return;
        e.preventDefault();
        var u2 = worldFromClient(e.clientX, e.clientY);
        drawing = true;
        cur = { id: 't' + Math.random().toString(36).slice(2), tool: tool, color: color, width: lineWidth,
                points: [[clamp(u2.wx / mapW, -0.02, 1.02), clamp(u2.wy / mapH, -0.02, 1.02)]] };
    });

    container.addEventListener('mousemove', function (e) {
        /* Tracking position pour la présence temps réel */
        var u = worldFromClient(e.clientX, e.clientY);
        lastNorm.nx = clamp(u.wx / mapW, 0, 1);
        lastNorm.ny = clamp(u.wy / mapH, 0, 1);

        if (panning && panStart) {
            tx = panStart.tx + (e.clientX - panStart.x);
            ty = panStart.ty + (e.clientY - panStart.y);
            applyTransform(); redraw();
        }
        if (drawing && cur && e.buttons === 1) {
            var nx = clamp(u.wx / mapW, -0.02, 1.02), ny = clamp(u.wy / mapH, -0.02, 1.02);
            var last = cur.points[cur.points.length - 1];
            if ((nx - last[0]) * (nx - last[0]) + (ny - last[1]) * (ny - last[1]) < 1e-8) return;
            cur.points.push([nx, ny]); redraw();
        }
    });
    window.addEventListener('mouseup', function (e) {
        if (e.button === 2) { panning = false; panStart = null; }
        if (e.button !== 0 || !drawing) return;
        drawing = false;
        if (cur && cur.points && cur.points.length > 1) {
            var s = { id: cur.id, tool: cur.tool, color: cur.color, width: cur.width, points: cur.points.slice() };
            strokes.push(s); cur = null; redraw();
            pushStroke(s).catch(function (err) {
                strokes = strokes.filter(function (x) { return x.id !== s.id; });
                redraw();
                setSave('err', '\u26a0 Erreur sync');
                setTimeout(function () { setSave('', 'Sauvegarde auto'); }, 3000);
            });
        } else { cur = null; redraw(); }
    });

    /* ─────────────────────────────────────────
       UI OUTILS
       ───────────────────────────────────────── */
    var btnPen = document.getElementById('toolPen');
    var btnMarker = document.getElementById('toolMarker');
    var btnErase = document.getElementById('toolEraser');
    var btnPoint = document.getElementById('toolPoint');
    var btnNav = document.getElementById('toolNav');
    var toolDrawButtons = [btnPen, btnMarker, btnErase, btnPoint, btnNav].filter(function (b) { return !!b; });
    function setActive(btn) {
        toolDrawButtons.forEach(function (b) { b.classList.remove('is-active'); });
        if (btn) btn.classList.add('is-active');
    }
    function applyPointModeClass() {
        container.classList.toggle('mv-tool-point', pointMode);
    }
    if (btnPen)    btnPen.addEventListener('click',   function () { tool = 'pen';    brushActive = true;  pointMode = false; container.style.cursor = 'crosshair'; setActive(btnPen);   applyPointModeClass(); });
    if (btnMarker) btnMarker.addEventListener('click', function () { tool = 'marker'; brushActive = true;  pointMode = false; container.style.cursor = 'crosshair'; setActive(btnMarker); applyPointModeClass(); });
    if (btnErase)  btnErase.addEventListener('click', function () { tool = 'eraser'; brushActive = true;  pointMode = false; container.style.cursor = 'crosshair'; setActive(btnErase); applyPointModeClass(); });
    if (btnPoint)  btnPoint.addEventListener('click',  function () {                 brushActive = false; pointMode = true;  container.style.cursor = 'copy';      setActive(btnPoint); applyPointModeClass(); });
    if (btnNav)    btnNav.addEventListener('click',   function () {                 brushActive = false; pointMode = false; container.style.cursor = 'default';   setActive(btnNav);   applyPointModeClass(); });

    var colorBtns = document.querySelectorAll('#mapColorPalette .color-btn');
    function setSwatchSelected(el) {
        for (var j = 0; j < colorBtns.length; j++) colorBtns[j].classList.remove('is-selected');
        if (el) el.classList.add('is-selected');
    }
    for (var i = 0; i < colorBtns.length; i++) (function (b) {
        b.addEventListener('click', function () {
            color = b.getAttribute('data-c') || b.dataset.c;
            setSwatchSelected(b);
        });
    })(colorBtns[i]);

    var wDisplay = document.getElementById('widthValueDisplay');
    function setWidthFromSlider(val) {
        lineWidth = parseInt(val, 10) || 3;
        if (wDisplay) wDisplay.textContent = lineWidth + ' px';
    }
    var slider = document.getElementById('widthSlider');
    if (slider) {
        slider.addEventListener('input', function () { setWidthFromSlider(this.value); });
        setWidthFromSlider(slider.value);
    }

    function zoomAt(f) {
        var sz = vpSize();
        var cx = sz.w / 2, cy = sz.h / 2;
        var wx = (cx - tx) / sc, wy = (cy - ty) / sc;
        var s2 = clamp(sc * f, 0.02, 32);
        tx = cx - wx * s2; ty = cy - wy * s2; sc = s2;
        applyTransform(); redraw();
    }
    var zi = document.getElementById('zoomIn'), zo = document.getElementById('zoomOut');
    if (zi) zi.addEventListener('click', function () { zoomAt(1.15); });
    if (zo) zo.addEventListener('click', function () { zoomAt(1 / 1.15); });

    document.addEventListener('keydown', function (e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (delModal && delModal.classList.contains('visible')) {
            if (e.key === 'Escape') { e.preventDefault(); closePointDeleteModal(); }
            return;
        }
        if (modal.classList.contains('visible')) {
            if (e.key === 'Escape') closePointModal();
            return;
        }
        if (e.key === 's' || e.key === 'S') { if (btnPen)    btnPen.click(); }
        if (e.key === 'g' || e.key === 'G') { if (btnErase)  btnErase.click(); }
        if (e.key === 'm' || e.key === 'M') { if (btnMarker) btnMarker.click(); }
        if (e.key === 'p' || e.key === 'P') { if (btnPoint)  btnPoint.click(); }
        if (e.key === 'n' || e.key === 'N') { if (btnNav)    btnNav.click(); }
    });

    var rt;
    window.addEventListener('resize', function () {
        clearTimeout(rt);
        rt = setTimeout(function () { resizeCanvas(); redraw(); renderPresence(); renderPoints(); }, 150);
    });

    /* ─────────────────────────────────────────
       DÉMARRAGE DE LA COLLABORATION
       ───────────────────────────────────────── */
    renderPoints(); /* NEW : affichage initial des points */
    pollNow();
    armPoll();
    sendPresence();
    armPresence();

    document.addEventListener('visibilitychange', function () {
        armPoll();
        armPresence();
        if (!document.hidden) {
            pollNow();
            sendPresence();
        }
    });

    window.addEventListener('beforeunload', function () {
        if (pollTimer) clearInterval(pollTimer);
        if (presenceTimer) clearInterval(presenceTimer);
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>