<?php
/**
 * pages/companies/dynasty/zone.php
 * ─────────────────────────────────────────────────────────────────────────
 * Zones & Tarification — pilotage du site public Dynasty.
 *
 * Onglet « Carte »        : tracé des quartiers sur le fond atlas, via le moteur
 *                           partagé assets/js/maps/map-engine.js (options
 *                           allowZones / vertexEdit / zoneHover, collab désactivé).
 * Onglet « Tarification » : grille de prix entièrement configurable.
 *
 * Toute écriture passe par api/zones_api.php, qui revérifie les permissions
 * côté serveur. Les boutons masqués ici ne sont qu'un confort d'interface.
 */

require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🏠 Dynasty';
$companyColor   = '#22c55e';
$companyFaction = 'DYNASTY';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Zones & Tarifs';
$pageSubtitle = 'Quartiers et grille tarifaire · Dynasty';
$db           = Database::getInstance()->getConnection();

require_once ROOT_PATH . '/includes/faction_acl.php';
require_once ROOT_PATH . '/includes/dynasty_pricing.php';
require_once ROOT_PATH . '/pages/maps/includes/map_assets.php';
require_once __DIR__ . '/includes/zones_bootstrap.php';

dynasty_zones_bootstrap($db);

/* ── Permissions granulaires (catalogue : entrée 'zone' de faction_acl.php) ── */
$uid = (int)($_SESSION['user_id'] ?? 0);
faction_acl_require($db, $uid, $companyFaction, 'zone.view');

$canCreate      = faction_acl_can($db, $uid, $companyFaction, 'zone.create');
$canEditZone    = faction_acl_can($db, $uid, $companyFaction, 'zone.edit');
$canDeleteZone  = faction_acl_can($db, $uid, $companyFaction, 'zone.delete');
$canPricingView = faction_acl_can($db, $uid, $companyFaction, 'zone.pricing_view');
$canPricingAdd  = faction_acl_can($db, $uid, $companyFaction, 'zone.pricing_add');
$canPricingEdit = faction_acl_can($db, $uid, $companyFaction, 'zone.pricing_edit');
$canPricingDel  = faction_acl_can($db, $uid, $companyFaction, 'zone.pricing_delete');

/* Le moteur n'autorise une interaction d'édition que si canEdit est vrai ;
   le détail se joue ensuite outil par outil (boutons absents du DOM). */
$engineCanEdit = ($canCreate || $canEditZone || $canDeleteZone);

/* ── Données ── */
$grille = dynasty_pricing_load($db, false);
$zones  = dynasty_zones_load($db, $grille['categories']);
$csrf   = dynasty_zones_csrf();
$bgKey  = (string) dynasty_pricing_setting($grille, 'background', 'atlas');

/* Nombre de zones par catégorie — sert l'avertissement avant suppression. */
$usageCat = [];
foreach ($zones as $z) {
    $cid = (int) ($z['meta']['categorie_id'] ?? 0);
    $usageCat[$cid] = ($usageCat[$cid] ?? 0) + 1;
}

$cfgJson = json_encode([
    'apiBase'           => SITE_URL . '/pages/companies/dynasty/api/zones_api.php',
    'target'            => ['id' => 0],           // carte unique : l'API ignore la cible
    'background'        => $bgKey,
    'backgroundSources' => maps_background_sources(),
    'elements'          => $zones,
    'revision'          => (int) ($grille['config']['zones_revision'] ?? 0),
    'csrf'              => $csrf,
    'canEdit'           => $engineCanEdit,
    'collab'            => false,                 // pas de polling ni de présence
    'allowZones'        => true,                  // outil zone hors carte Gang
    'vertexEdit'        => $canEditZone,
    'zoneHover'         => true,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

$dataJson = json_encode([
    'grille'   => dynasty_pricing_for_js($grille),
    'usageCat' => (object) $usageCat,
    'perms'    => [
        'create'         => $canCreate,
        'edit'           => $canEditZone,
        'delete'         => $canDeleteZone,
        'pricing_view'   => $canPricingView,
        'pricing_add'    => $canPricingAdd,
        'pricing_edit'   => $canPricingEdit,
        'pricing_delete' => $canPricingDel,
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

include __DIR__ . '/includes/header.php';
?>

<style>
/* ============================================================
   ZONES & TARIFICATION — styles locaux (préfixe dyz-)
   Reprend les variables du design system Dynasty (--dy, --surface…)
   ============================================================ */

/* ── Onglets ── */
.dyz-tabs { display:inline-flex; gap:.25rem; padding:.28rem; background:var(--surface); border:1px solid var(--bd); border-radius:var(--r-pill); margin-bottom:1.1rem; }
.dyz-tab { border:0; background:transparent; color:var(--tx-3); font:600 .82rem/1 'Inter',sans-serif; padding:.6rem 1.15rem; border-radius:var(--r-pill); cursor:pointer; display:flex; align-items:center; gap:.45rem; transition:background .16s,color .16s; }
.dyz-tab:hover { color:var(--tx-2); }
.dyz-tab.active { background:var(--dy); color:#04140e; }
.dyz-panel { display:none; }
.dyz-panel.active { display:block; }

/* ── Mise en page carte ── */
.dyz-map-wrap { display:grid; grid-template-columns:1fr 310px; gap:1rem; height:calc(100vh - 232px); min-height:540px; }
.dyz-stage-col { position:relative; background:var(--surface); border:1px solid var(--bd); border-radius:var(--r-lg); overflow:hidden; }
.dyz-stage { position:absolute; inset:0; overflow:hidden; cursor:grab; background:#080b10; }
.dyz-plane { position:absolute; transform-origin:0 0; }
.dyz-plane img { display:block; max-width:none; user-select:none; }
.dyz-canvas, .dyz-overlay { position:absolute; inset:0; }
.dyz-overlay { pointer-events:none; }
.dyz-overlay > * { pointer-events:auto; }

/* Libellé de zone posé par le moteur (.zone-hotspot / .mk-label) */
.zone-hotspot { position:absolute; transform:translate(-50%,-50%); cursor:pointer; }
.zone-hotspot .mk-label { display:inline-block; padding:.2rem .5rem; border-radius:7px; border:1px solid; font:600 .7rem/1.3 'Inter',sans-serif; color:#e2e8f0; white-space:nowrap; backdrop-filter:blur(3px); }

/* ── Barre d'outils flottante ── */
.dyz-toolbar { position:absolute; top:.75rem; left:.75rem; right:.75rem; z-index:5; display:flex; flex-wrap:wrap; align-items:center; gap:.4rem; padding:.5rem .6rem; background:rgba(15,20,27,.94); border:1px solid var(--bd-2); border-radius:var(--r-md); box-shadow:var(--shadow-2); backdrop-filter:blur(8px); }
.dyz-tool { border:1px solid var(--bd-2); background:var(--surface-2); color:var(--tx-2); border-radius:var(--r-sm); padding:.42rem .7rem; font:600 .75rem/1 'Inter',sans-serif; cursor:pointer; display:inline-flex; align-items:center; gap:.4rem; transition:background .15s,border-color .15s,color .15s; }
.dyz-tool:hover:not(:disabled) { background:var(--surface-3); color:var(--tx); }
.dyz-tool.active { background:var(--dy); border-color:var(--dy); color:#04140e; }
.dyz-tool:disabled { opacity:.4; cursor:not-allowed; }
.dyz-tool-sep { width:1px; align-self:stretch; background:var(--bd-2); margin:0 .2rem; }

/* Pastilles de catégorie */
.dyz-cats { display:inline-flex; align-items:center; gap:.3rem; }
.dyz-cat { border:1px solid var(--bd-2); background:var(--surface-2); border-radius:var(--r-pill); padding:.32rem .68rem; font:600 .73rem/1 'Inter',sans-serif; color:var(--tx-3); cursor:pointer; display:inline-flex; align-items:center; gap:.4rem; transition:all .15s; }
.dyz-cat .dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
.dyz-cat.active { color:var(--tx); border-color:currentColor; background:var(--surface-3); }

/* ── Indicateurs ── */
.dyz-hint { position:absolute; left:.75rem; bottom:.75rem; z-index:5; display:flex; align-items:center; gap:.7rem; padding:.4rem .7rem; background:rgba(15,20,27,.9); border:1px solid var(--bd); border-radius:var(--r-sm); font-size:.7rem; color:var(--tx-3); backdrop-filter:blur(6px); }
.dyz-save .dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:var(--dy); margin-right:.35rem; }
.dyz-save.saving .dot { background:var(--warn); }
.dyz-zoom { position:absolute; right:.75rem; bottom:.75rem; z-index:5; display:flex; flex-direction:column; gap:.3rem; }
.dyz-zoom button { width:32px; height:32px; border:1px solid var(--bd-2); background:rgba(15,20,27,.92); color:var(--tx-2); border-radius:var(--r-sm); cursor:pointer; }
.dyz-zoom button:hover { background:var(--surface-3); color:var(--tx); }

/* Infobulle de survol */
.dyz-tip { position:absolute; z-index:6; pointer-events:none; display:none; padding:.35rem .6rem; background:rgba(8,11,16,.95); border:1px solid var(--bd-2); border-radius:var(--r-sm); font-size:.72rem; color:var(--tx); white-space:nowrap; transform:translate(-50%,-160%); }
.dyz-tip .cat { display:block; font-size:.65rem; color:var(--tx-3); margin-top:.1rem; }

/* ── Liste latérale ── */
.dyz-side { background:var(--surface); border:1px solid var(--bd); border-radius:var(--r-lg); display:flex; flex-direction:column; overflow:hidden; }
.dyz-side-head { padding:.85rem 1rem; border-bottom:1px solid var(--bd); display:flex; align-items:center; justify-content:space-between; }
.dyz-side-head h3 { font-size:.82rem; font-weight:700; color:var(--tx); }
.dyz-side-count { font-size:.7rem; color:var(--tx-3); }
.dyz-side-search { padding:.6rem .75rem; border-bottom:1px solid var(--bd); }
.dyz-list { flex:1; overflow-y:auto; padding:.4rem; }
.dyz-item { display:flex; align-items:center; gap:.6rem; padding:.55rem .6rem; border-radius:var(--r-sm); cursor:pointer; transition:background .14s; }
.dyz-item:hover { background:var(--surface-2); }
.dyz-item.hot { background:var(--surface-3); }
.dyz-item-bar { width:4px; align-self:stretch; border-radius:2px; flex-shrink:0; }
.dyz-item-info { flex:1; min-width:0; }
.dyz-item-nom { font-size:.79rem; font-weight:600; color:var(--tx); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.dyz-item-cat { font-size:.68rem; color:var(--tx-3); }
.dyz-item-act { display:flex; gap:.25rem; opacity:0; transition:opacity .14s; }
.dyz-item:hover .dyz-item-act { opacity:1; }
.dyz-empty { padding:2rem 1rem; text-align:center; color:var(--tx-mute); font-size:.78rem; }

/* ── Écran trop étroit : l'édition cartographique n'a pas de sens ── */
.dyz-narrow { display:none; padding:2.2rem 1.4rem; text-align:center; color:var(--tx-3); }
.dyz-narrow i { font-size:1.8rem; color:var(--warn); margin-bottom:.7rem; display:block; }
@media (max-width:1023px) {
    .dyz-map-wrap { display:none; }
    .dyz-narrow   { display:block; }
}

/* ============================================================
   ONGLET TARIFICATION
   ============================================================ */
.dyz-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; align-items:start; }
@media (max-width:1100px) { .dyz-grid { grid-template-columns:1fr; } }

.dyz-bloc { background:var(--surface); border:1px solid var(--bd); border-radius:var(--r-lg); overflow:hidden; }
.dyz-bloc-head { padding:.85rem 1rem; border-bottom:1px solid var(--bd); display:flex; align-items:center; justify-content:space-between; gap:.6rem; }
.dyz-bloc-head h3 { font-size:.84rem; font-weight:700; color:var(--tx); display:flex; align-items:center; gap:.5rem; }
.dyz-bloc-head p { font-size:.7rem; color:var(--tx-3); margin-top:.15rem; font-weight:500; }
.dyz-bloc-body { padding:.6rem; }

/* Tableau d'édition */
.dyz-tab-wrap { overflow-x:auto; }
table.dyz-t { width:100%; border-collapse:collapse; font-size:.76rem; min-width:520px; }
table.dyz-t th { text-align:left; font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--tx-mute); padding:.4rem .45rem; border-bottom:1px solid var(--bd); white-space:nowrap; }
table.dyz-t td { padding:.3rem .45rem; border-bottom:1px solid var(--bd); vertical-align:middle; }
table.dyz-t tr:last-child td { border-bottom:0; }
table.dyz-t input[type=text], table.dyz-t input[type=number], table.dyz-t select {
    width:100%; background:var(--bg-2); border:1px solid var(--bd-2); color:var(--tx);
    border-radius:6px; padding:.35rem .45rem; font:500 .76rem 'Inter',sans-serif;
}
table.dyz-t input[type=number] { text-align:right; font-family:'JetBrains Mono',monospace; }
table.dyz-t input[type=color] { width:34px; height:28px; padding:0; border:1px solid var(--bd-2); border-radius:6px; background:var(--bg-2); cursor:pointer; }
table.dyz-t input:focus, table.dyz-t select:focus { outline:0; border-color:var(--dy); }
.dyz-t .col-act { width:1%; white-space:nowrap; }
.dyz-t tr.sale td { background:rgba(245,158,11,.06); }
.dyz-cell-inline { display:flex; align-items:center; gap:.35rem; }

.dyz-bloc-foot { padding:.6rem .8rem; border-top:1px solid var(--bd); background:var(--bg-2); }

/* Réglages globaux */
.dyz-reglages { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.9rem; padding:.4rem; }
.dyz-reglages .form-input, .dyz-reglages .form-select { font-family:'JetBrains Mono',monospace; }

/* Aperçu du calcul */
.dyz-apercu { margin-top:1rem; background:linear-gradient(150deg,#0e1a16,#0b1220); border:1px solid rgba(34,197,94,.22); border-radius:var(--r-lg); overflow:hidden; }
.dyz-apercu-head { padding:.8rem 1rem; border-bottom:1px solid rgba(34,197,94,.16); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.6rem; }
.dyz-apercu-body { display:grid; grid-template-columns:1fr 300px; gap:1rem; padding:1rem; }
@media (max-width:900px) { .dyz-apercu-body { grid-template-columns:1fr; } }
.dyz-sim { display:flex; flex-direction:column; gap:.7rem; }
.dyz-sim-ligne { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; }
.dyz-sim-ligne > label { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--tx-mute); min-width:92px; }
.dyz-seg { display:inline-flex; background:var(--surface-2); border:1px solid var(--bd-2); border-radius:var(--r-pill); padding:.2rem; }
.dyz-seg button { border:0; background:transparent; color:var(--tx-3); font:600 .74rem 'Inter',sans-serif; padding:.35rem .9rem; border-radius:var(--r-pill); cursor:pointer; }
.dyz-seg button.active { background:var(--dy); color:#04140e; }
.dyz-chip { border:1px solid var(--bd-2); background:var(--surface-2); color:var(--tx-3); border-radius:var(--r-pill); padding:.3rem .7rem; font:600 .72rem 'Inter',sans-serif; cursor:pointer; }
.dyz-chip.active { border-color:var(--dy); color:var(--dy); background:rgba(16,185,129,.10); }

/* Barre de facturation — miroir du site public */
.dyz-facture { background:var(--surface); border:1px solid var(--bd-2); border-radius:var(--r-md); padding:1rem; display:flex; flex-direction:column; gap:.55rem; }
.dyz-fl { display:flex; justify-content:space-between; font-size:.76rem; color:var(--tx-3); }
.dyz-fl b { color:var(--tx-2); font-family:'JetBrains Mono',monospace; font-weight:600; }
.dyz-fsep { height:1px; background:var(--bd); margin:.2rem 0; }
.dyz-total-lbl { font-size:.64rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--tx-mute); }
.dyz-total { font:800 1.9rem/1 'JetBrains Mono',monospace; color:var(--dy); letter-spacing:-.02em; }
.dyz-controle { font-size:.68rem; color:var(--tx-mute); margin-top:.35rem; }
.dyz-controle.ok { color:var(--dy); }
.dyz-controle.ko { color:var(--danger); }

/* ============================================================
   CONTRÔLES DE FORMULAIRE MODERNISÉS
   Portée limitée aux conteneurs de cette page (tarification,
   réglages, modale) — n'affecte aucune autre page du panel.
   ============================================================ */

/* Accent natif (curseurs, sélection) sur toute la page */
#panel-tarifs, #panel-carte, #dyzZoneModal { accent-color: var(--dy); }

/* ── Menus déroulants (table.dyz-t : même spécificité que la règle de base) ── */
table.dyz-t select,
.dyz-reglages .form-select,
#dyzZoneModal .form-select {
    -webkit-appearance:none; -moz-appearance:none; appearance:none;
    background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right .65rem center;
    padding-right:2rem; cursor:pointer;
}
table.dyz-t select { padding-right:1.7rem; background-position:right .4rem center; }
.dyz-reglages .form-select:hover, #dyzZoneModal .form-select:hover { border-color:var(--bd-h); }

/* ── Cases à cocher ── */
.dyz-t input[type=checkbox] {
    -webkit-appearance:none; appearance:none;
    width:20px; height:20px; margin:0; border:1.6px solid var(--bd-2); border-radius:6px;
    background:var(--bg-2); cursor:pointer; position:relative; vertical-align:middle;
    transition:background .15s, border-color .15s, box-shadow .15s;
}
.dyz-t input[type=checkbox]:hover { border-color:var(--dy); }
.dyz-t input[type=checkbox]:focus-visible { outline:0; box-shadow:0 0 0 3px var(--dy-soft); }
.dyz-t input[type=checkbox]:checked { background:var(--dy); border-color:var(--dy); }
.dyz-t input[type=checkbox]:checked::after {
    content:''; position:absolute; left:6px; top:2px; width:5px; height:10px;
    border:solid #04140e; border-width:0 2.2px 2.2px 0; transform:rotate(45deg);
}

/* ── Champs numériques (spinners masqués pour un rendu épuré) ── */
.dyz-t input[type=number]::-webkit-outer-spin-button,
.dyz-t input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
.dyz-t input[type=number] { -moz-appearance:textfield; }

/* ── Focus uniforme sur tous les champs ── */
.dyz-t input:focus, .dyz-t select:focus,
.dyz-reglages .form-input:focus, .dyz-reglages .form-select:focus,
#dyzZoneModal .form-input:focus, #dyzZoneModal .form-select:focus {
    outline:0; border-color:var(--dy); box-shadow:0 0 0 3px var(--dy-soft);
}

/* ── Sélecteur de couleur ── */
.dyz-t input[type=color] { -webkit-appearance:none; appearance:none; }
.dyz-t input[type=color]::-webkit-color-swatch-wrapper { padding:0; }
.dyz-t input[type=color]::-webkit-color-swatch { border:none; border-radius:4px; }
.dyz-t input[type=color]::-moz-color-swatch { border:none; border-radius:4px; }
</style>

<!-- ══ ONGLETS ══ -->
<div class="dyz-tabs" role="tablist">
    <button class="dyz-tab active" data-panel="carte" role="tab"><i class="fas fa-draw-polygon"></i> Carte</button>
    <?php if ($canPricingView): ?>
    <button class="dyz-tab" data-panel="tarifs" role="tab"><i class="fas fa-tags"></i> Tarification</button>
    <?php endif; ?>
</div>

<!-- ══════════════════ ONGLET CARTE ══════════════════ -->
<div class="dyz-panel active" id="panel-carte">

    <div class="dyz-narrow card">
        <i class="fas fa-desktop"></i>
        <strong style="color:var(--tx);display:block;margin-bottom:.35rem">Écran trop étroit</strong>
        Le tracé des quartiers demande une largeur d'au moins 1024 px.
        <?php if ($canPricingView): ?>L'onglet <b>Tarification</b> reste utilisable ici.<?php endif; ?>
    </div>

    <div class="dyz-map-wrap">
        <div class="dyz-stage-col">

            <div class="dyz-toolbar">
                <?php if (!empty($grille['categories'])): ?>
                <div class="dyz-cats" id="dyzCats">
                    <?php foreach ($grille['categories'] as $c): if (!$c['actif']) continue; ?>
                    <button class="dyz-cat" data-cat="<?php echo (int)$c['id']; ?>"
                            data-couleur="<?php echo htmlspecialchars($c['couleur']); ?>"
                            style="--c:<?php echo htmlspecialchars($c['couleur']); ?>">
                        <span class="dot" style="background:<?php echo htmlspecialchars($c['couleur']); ?>"></span>
                        <?php echo htmlspecialchars($c['label']); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="dyz-tool-sep"></div>
                <?php endif; ?>

                <button class="dyz-tool active" data-tool="move" title="Se déplacer sur la carte">
                    <i class="fas fa-up-down-left-right"></i> Déplacer
                </button>
                <?php if ($canCreate): ?>
                <button class="dyz-tool" data-tool="zone" title="Cliquez les sommets, puis Enregistrer (ou double-clic)">
                    <i class="fas fa-draw-polygon"></i> Dessiner
                </button>
                <?php endif; ?>
                <?php if ($canEditZone): ?>
                <button class="dyz-tool" data-tool="vertex" title="Glissez un sommet pour retoucher un tracé">
                    <i class="fas fa-arrows-up-down-left-right"></i> Déplacer un point
                </button>
                <?php endif; ?>
                <?php if ($canDeleteZone): ?>
                <button class="dyz-tool" data-tool="erase" title="Cliquez une zone pour la supprimer">
                    <i class="fas fa-trash"></i> Supprimer
                </button>
                <?php endif; ?>

                <?php if ($canCreate): ?>
                <div class="dyz-tool-sep"></div>
                <button class="dyz-tool" id="dyzCancel" disabled title="Abandonner le tracé en cours">
                    <i class="fas fa-rotate-left"></i> Annuler
                </button>
                <button class="dyz-tool" id="dyzFinish" disabled title="Refermer le polygone et le nommer">
                    <i class="fas fa-check"></i> Enregistrer
                </button>
                <?php endif; ?>

                <div class="dyz-tool-sep"></div>
                <button class="dyz-tool" id="dyzExport" title="Exporter toutes les zones en JSON">
                    <i class="fas fa-file-export"></i> Exporter
                </button>
                <?php if ($canCreate): ?>
                <button class="dyz-tool" id="dyzImport" title="Importer des zones depuis un JSON">
                    <i class="fas fa-file-import"></i> Importer
                </button>
                <input type="file" id="dyzImportFile" accept="application/json,.json" hidden>
                <?php endif; ?>
            </div>

            <div class="dyz-stage" id="dyzStage">
                <div class="dyz-plane" id="dyzPlane"><img id="dyzImg" alt="Fond de carte" src=""></div>
                <canvas class="dyz-canvas" id="dyzCanvas"></canvas>
                <div class="dyz-overlay" id="dyzOverlay"></div>
                <div class="dyz-tip" id="dyzTip"></div>
            </div>

            <div class="dyz-hint">
                <span><i class="fas fa-computer-mouse"></i> Clic droit ou molette : déplacer / zoomer</span>
                <?php if ($engineCanEdit): ?>
                <span class="dyz-save" id="dyzSave"><span class="dot"></span> Enregistré</span>
                <?php endif; ?>
            </div>

            <div class="dyz-zoom">
                <button id="dyzZoomIn" title="Zoom avant"><i class="fas fa-plus"></i></button>
                <button id="dyzZoomOut" title="Zoom arrière"><i class="fas fa-minus"></i></button>
                <button id="dyzZoomFit" title="Recentrer"><i class="fas fa-expand"></i></button>
            </div>
        </div>

        <!-- ── Liste latérale ── -->
        <aside class="dyz-side">
            <div class="dyz-side-head">
                <h3><i class="fas fa-layer-group" style="color:var(--dy)"></i> Quartiers</h3>
                <span class="dyz-side-count" id="dyzCount">0</span>
            </div>
            <div class="dyz-side-search">
                <input type="text" class="form-input" id="dyzSearch" placeholder="Rechercher un quartier…" autocomplete="off">
            </div>
            <div class="dyz-list" id="dyzList"></div>
        </aside>
    </div>
</div>

<?php if ($canPricingView): ?>
<!-- ══════════════════ ONGLET TARIFICATION ══════════════════ -->
<div class="dyz-panel" id="panel-tarifs">
    <div class="dyz-grid">

        <!-- ── Catégories de quartier ── -->
        <section class="dyz-bloc">
            <div class="dyz-bloc-head">
                <div>
                    <h3><i class="fas fa-layer-group" style="color:var(--dy)"></i> Catégories de quartier</h3>
                    <p>Prix de base appliqué à toute zone de la catégorie.</p>
                </div>
            </div>
            <div class="dyz-bloc-body dyz-tab-wrap">
                <table class="dyz-t">
                    <thead><tr>
                        <th style="width:34px"></th><th>Libellé</th>
                        <th style="width:110px">Base location</th><th style="width:110px">Base achat</th>
                        <th style="width:56px">Actif</th><th class="col-act"></th>
                    </tr></thead>
                    <tbody id="tCats"></tbody>
                </table>
            </div>
            <?php if ($canPricingAdd): ?>
            <div class="dyz-bloc-foot">
                <button class="btn btn-secondary btn-sm" id="addCat"><i class="fas fa-plus"></i> Ajouter une catégorie</button>
            </div>
            <?php endif; ?>
        </section>

        <!-- ── Options ── -->
        <section class="dyz-bloc">
            <div class="dyz-bloc-head">
                <div>
                    <h3><i class="fas fa-sliders" style="color:var(--dy)"></i> Options</h3>
                    <p>« Fixe » s'additionne · « Multiplicateur » est un facteur (1.15 = +15 %).</p>
                </div>
            </div>
            <div class="dyz-bloc-body dyz-tab-wrap">
                <table class="dyz-t">
                    <thead><tr>
                        <th>Libellé</th><th style="width:118px">Type</th>
                        <th style="width:100px">Location</th><th style="width:100px">Achat</th>
                        <th style="width:56px">Actif</th><th class="col-act"></th>
                    </tr></thead>
                    <tbody id="tOpts"></tbody>
                </table>
            </div>
            <?php if ($canPricingAdd): ?>
            <div class="dyz-bloc-foot">
                <button class="btn btn-secondary btn-sm" id="addOpt"><i class="fas fa-plus"></i> Ajouter une option</button>
            </div>
            <?php endif; ?>
        </section>

        <!-- ── Capacités de garage ── -->
        <section class="dyz-bloc">
            <div class="dyz-bloc-head">
                <div>
                    <h3><i class="fas fa-warehouse" style="color:var(--dy)"></i> Capacités de garage</h3>
                    <p>Supplément appliqué selon le nombre de places.</p>
                </div>
            </div>
            <div class="dyz-bloc-body dyz-tab-wrap">
                <table class="dyz-t">
                    <thead><tr>
                        <th style="width:86px">Places</th>
                        <th style="width:118px">Supp. location</th><th style="width:118px">Supp. achat</th>
                        <th>Icône <span style="text-transform:none;font-weight:400">(facultatif)</span></th>
                        <th style="width:56px">Actif</th><th class="col-act"></th>
                    </tr></thead>
                    <tbody id="tGars"></tbody>
                </table>
            </div>
            <?php if ($canPricingAdd): ?>
            <div class="dyz-bloc-foot">
                <button class="btn btn-secondary btn-sm" id="addGar"><i class="fas fa-plus"></i> Ajouter un palier</button>
            </div>
            <?php endif; ?>
        </section>

        <!-- ── Réglages globaux ── -->
        <section class="dyz-bloc">
            <div class="dyz-bloc-head">
                <div>
                    <h3><i class="fas fa-gear" style="color:var(--dy)"></i> Réglages globaux</h3>
                    <p>S'appliquent à tous les calculs, panel comme site public.</p>
                </div>
            </div>
            <div class="dyz-reglages">
                <div class="form-group">
                    <label class="form-label" for="cfgTaxe">Taux de taxe (%)</label>
                    <input type="number" class="form-input" id="cfgTaxe" min="0" max="1000" step="0.01" <?php echo $canPricingEdit ? '' : 'disabled'; ?>>
                </div>
                <div class="form-group">
                    <label class="form-label" for="cfgDevise">Devise</label>
                    <input type="text" class="form-input" id="cfgDevise" maxlength="8" <?php echo $canPricingEdit ? '' : 'disabled'; ?>>
                </div>
                <div class="form-group">
                    <label class="form-label" for="cfgMult">Multiplicateur du mode Achat</label>
                    <input type="number" class="form-input" id="cfgMult" min="0.0001" max="1000" step="0.0001" <?php echo $canPricingEdit ? '' : 'disabled'; ?>>
                    <div class="form-hint">1 = prix d'achat repris tel quel.</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="cfgBg">Fond de carte</label>
                    <select class="form-select" id="cfgBg" <?php echo $canPricingEdit ? '' : 'disabled'; ?>>
                        <option value="atlas">Atlas</option>
                        <option value="roadmap">Roadmap</option>
                        <option value="satellite">Satellite</option>
                    </select>
                </div>
            </div>
            <?php if ($canPricingEdit): ?>
            <div class="dyz-bloc-foot" style="display:flex;justify-content:flex-end">
                <button class="btn btn-primary btn-sm" id="saveCfg"><i class="fas fa-check"></i> Enregistrer les réglages</button>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- ── Aperçu du calcul en direct ── -->
    <div class="dyz-apercu">
        <div class="dyz-apercu-head">
            <div>
                <h3 style="font-size:.84rem;font-weight:700;color:var(--tx);display:flex;align-items:center;gap:.5rem">
                    <i class="fas fa-calculator" style="color:var(--dy)"></i> Aperçu du calcul
                </h3>
                <p style="font-size:.7rem;color:var(--tx-3);margin-top:.15rem">
                    Reflète les valeurs <b>saisies dans les champs ci-dessus</b>, avant enregistrement.
                    Rendu identique à la barre de facturation du site public.
                </p>
            </div>
            <div class="dyz-controle" id="dyzControle"></div>
        </div>
        <div class="dyz-apercu-body">
            <div class="dyz-sim">
                <div class="dyz-sim-ligne">
                    <label>Mode</label>
                    <div class="dyz-seg" id="simMode">
                        <button class="active" data-mode="location">Location</button>
                        <button data-mode="achat">Achat</button>
                    </div>
                </div>
                <div class="dyz-sim-ligne"><label>Quartier</label><div id="simCats" style="display:flex;gap:.35rem;flex-wrap:wrap"></div></div>
                <div class="dyz-sim-ligne"><label>Options</label><div id="simOpts" style="display:flex;gap:.35rem;flex-wrap:wrap"></div></div>
                <div class="dyz-sim-ligne"><label>Garage</label><div id="simGars" style="display:flex;gap:.35rem;flex-wrap:wrap"></div></div>
            </div>
            <div class="dyz-facture">
                <div class="dyz-fl"><span>Base</span><b id="fBase">—</b></div>
                <div class="dyz-fl"><span>Options</span><b id="fOpts">—</b></div>
                <div class="dyz-fl"><span>Garage</span><b id="fGar">—</b></div>
                <div class="dyz-fsep"></div>
                <div class="dyz-fl"><span>Sous-total</span><b id="fST">—</b></div>
                <div class="dyz-fl"><span id="fTaxeLbl">Taxe</span><b id="fTaxe">—</b></div>
                <div class="dyz-fsep"></div>
                <div>
                    <div class="dyz-total-lbl">Montant estimé</div>
                    <div class="dyz-total" id="fTotal">—</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ MODALE ZONE ══ -->
<div class="modal-overlay hidden" id="dyzZoneModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 id="dyzModalTitle"><i class="fas fa-draw-polygon" style="color:var(--dy)"></i> Nouveau quartier</h3>
            <button class="modal-close" onclick="Company.closeModal('dyzZoneModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label" for="dyzNom">Nom du quartier</label>
                <input type="text" class="form-input" id="dyzNom" maxlength="150" placeholder="Ex : Vinewood Hills" autocomplete="off">
            </div>
            <div class="form-group" style="margin-top:1rem">
                <label class="form-label" for="dyzCat">Catégorie</label>
                <select class="form-select" id="dyzCat">
                    <?php foreach ($grille['categories'] as $c): if (!$c['actif']) continue; ?>
                    <option value="<?php echo (int)$c['id']; ?>" data-couleur="<?php echo htmlspecialchars($c['couleur']); ?>">
                        <?php echo htmlspecialchars($c['label']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint">La couleur du tracé suit celle de la catégorie.</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="Company.closeModal('dyzZoneModal')">Annuler</button>
            <button class="btn btn-primary" id="dyzZoneSave"><i class="fas fa-check"></i> Enregistrer</button>
        </div>
    </div>
</div>

<script>window.DYZ_CFG = <?php echo $cfgJson; ?>; window.DYZ_DATA = <?php echo $dataJson; ?>;</script>
<script src="<?php echo SITE_URL; ?>/assets/js/maps/map-engine.js?v=<?php echo @filemtime(ROOT_PATH . '/assets/js/maps/map-engine.js') ?: '1'; ?>"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/dynasty/dynasty-pricing.js?v=<?php echo @filemtime(ROOT_PATH . '/assets/js/dynasty/dynasty-pricing.js') ?: '1'; ?>"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/dynasty/dynasty-ui.js?v=<?php echo @filemtime(ROOT_PATH . '/assets/js/dynasty/dynasty-ui.js') ?: '1'; ?>"></script>
<script>
/* ══════════════════════════════════════════════════════════════════════════
   Onglets — bloc autonome : la Tarification doit rester utilisable même
   quand l'éditeur de carte n'est pas monté (écran étroit).
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';
    document.querySelectorAll('.dyz-tab').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.dyz-tab').forEach(x => x.classList.remove('active'));
            document.querySelectorAll('.dyz-panel').forEach(x => x.classList.remove('active'));
            t.classList.add('active');
            const p = document.getElementById('panel-' + t.dataset.panel);
            if (p) p.classList.add('active');
            // Le canevas a été dimensionné dans un conteneur masqué : on recadre.
            if (t.dataset.panel === 'carte' && window.__dyzEngine) window.__dyzEngine.resetView();
        });
    });
})();

/* ══════════════════════════════════════════════════════════════════════════
   Onglet Carte
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';
    const CFG   = window.DYZ_CFG;
    const DATA  = window.DYZ_DATA;
    const PERMS = DATA.perms;
    const CATS  = DATA.grille.categories || {};
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    let engine = null;

    /* L'éditeur n'existe pas sous 1024 px (cf. .dyz-map-wrap { display:none }). */
    if (!$('dyzStage')) return;

    /* ── Catégorie active : pilote la couleur du prochain tracé ── */
    let catActive = null;
    const catBtns = document.querySelectorAll('.dyz-cat');
    function setCat(id) {
        catActive = parseInt(id, 10) || null;
        catBtns.forEach(b => b.classList.toggle('active', parseInt(b.dataset.cat, 10) === catActive));
        const c = CATS[catActive];
        if (c && engine) engine.setColor(c.couleur);
    }
    catBtns.forEach(b => b.addEventListener('click', () => setCat(b.dataset.cat)));

    /* ══════════════════ Moteur de carte ══════════════════ */
    let zoneCtx = null;   // {mode:'create', points} | {mode:'edit', el}

    engine = new MdtMapEngine(Object.assign({}, CFG, {
        stage:   $('dyzStage'),
        plane:   $('dyzPlane'),
        img:     $('dyzImg'),
        canvas:  $('dyzCanvas'),
        overlay: $('dyzOverlay'),
        onZoneCreate: function (points) { openZoneModal({ mode: 'create', points: points }); },
        onZoneEdit:   function (el)     { if (el && PERMS.edit) openZoneModal({ mode: 'edit', el: el }); },
        onZoneClick:  function (el)     { onZoneClicked(el); },
        onZoneHover:  function (el, mx, my) { showTip(el, mx, my); },
        onSaveState:  function (st)     { saveState(st); }
    }));
    engine.init();
    window.__dyzEngine = engine;   // l'onglet Carte doit se recadrer quand il redevient visible

    // Première catégorie sélectionnée par défaut
    if (catBtns.length) setCat(catBtns[0].dataset.cat);

    /* ── Outils ── */
    let outil = 'move';
    document.querySelectorAll('.dyz-tool[data-tool]').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.dyz-tool[data-tool]').forEach(x => x.classList.remove('active'));
            b.classList.add('active');
            outil = b.dataset.tool;
            // « Supprimer » n'est pas un outil du moteur : on reste en déplacement
            // et c'est onZoneClick qui décide quoi faire du clic.
            engine.setTool(outil === 'erase' ? 'move' : outil);
            majBoutonsTrace();
        });
    });

    function majBoutonsTrace() {
        const enCours = engine.zonePts.length;
        const c = $('dyzCancel'), f = $('dyzFinish');
        if (c) c.disabled = !enCours;
        if (f) f.disabled = enCours < 3;
    }
    // Le moteur n'expose pas d'événement sur le polygone en cours : on suit son état.
    setInterval(majBoutonsTrace, 200);

    const btnCancel = $('dyzCancel');
    if (btnCancel) btnCancel.addEventListener('click', function () {
        engine.zonePts = []; engine.draw(); majBoutonsTrace();
    });
    const btnFinish = $('dyzFinish');
    if (btnFinish) btnFinish.addEventListener('click', function () {
        if (engine.zonePts.length >= 3) engine.finishZone();
        majBoutonsTrace();
    });

    /* ── Zoom ── */
    $('dyzZoomIn').addEventListener('click', () => engine.zoomIn());
    $('dyzZoomOut').addEventListener('click', () => engine.zoomOut());
    $('dyzZoomFit').addEventListener('click', () => engine.resetView());

    /* ── Indicateur de sauvegarde ── */
    function saveState(st) {
        const el = $('dyzSave'); if (!el) return;
        if (st === 'saving') { el.classList.add('saving'); el.innerHTML = '<span class="dot"></span> Enregistrement…'; }
        else if (st === 'error') { el.classList.remove('saving'); el.innerHTML = '<span class="dot" style="background:var(--danger)"></span> Erreur'; Company.toast('Enregistrement refusé par le serveur.', 'error'); }
        else { el.classList.remove('saving'); el.innerHTML = '<span class="dot"></span> Enregistré'; majListe(); }
    }

    /* ── Infobulle de survol ── */
    const tip = $('dyzTip');
    function showTip(el, mx, my) {
        if (!el) { tip.style.display = 'none'; surlignerListe(null); return; }
        const cat = CATS[(el.meta && el.meta.categorie_id) || 0];
        tip.innerHTML = esc(el.title || 'Zone') +
            '<span class="cat">' + esc(cat ? cat.label : 'Sans catégorie') + '</span>';
        tip.style.left = mx + 'px';
        tip.style.top  = my + 'px';
        tip.style.display = 'block';
        surlignerListe(el.id);
    }

    /* ── Clic sur une zone : dépend de l'outil actif ── */
    function onZoneClicked(el) {
        if (!el) return;
        if (outil === 'erase' && PERMS.delete) {
            if (confirm('Supprimer le quartier « ' + (el.title || 'Zone') + ' » ?\n\nLes biens rattachés ne sont pas supprimés, ils sont simplement détachés.')) {
                engine.deleteZone(el.id);
                setTimeout(majListe, 60);
            }
            return;
        }
        if (PERMS.edit) openZoneModal({ mode: 'edit', el: el });
    }

    /* ══════════════════ Modale zone ══════════════════ */
    function openZoneModal(ctx) {
        zoneCtx = ctx;
        $('dyzModalTitle').innerHTML = '<i class="fas fa-draw-polygon" style="color:var(--dy)"></i> ' +
            (ctx.mode === 'edit' ? 'Modifier le quartier' : 'Nouveau quartier');
        $('dyzNom').value = ctx.mode === 'edit' ? (ctx.el.title || '') : '';
        const catId = ctx.mode === 'edit'
            ? ((ctx.el.meta && ctx.el.meta.categorie_id) || catActive)
            : catActive;
        if (catId) $('dyzCat').value = String(catId);
        // Le menu déroulant custom doit refléter la valeur posée par programme.
        if (window.DynastyUI) { DynastyUI.enhanceSelects($('dyzZoneModal')); DynastyUI.refresh($('dyzCat')); }
        Company.openModal('dyzZoneModal');
        setTimeout(() => $('dyzNom').focus(), 60);
    }

    $('dyzZoneSave').addEventListener('click', function () {
        if (!zoneCtx) return;
        const nom   = $('dyzNom').value.trim();
        const catId = parseInt($('dyzCat').value, 10) || 0;
        if (!nom) { Company.toast('Donnez un nom au quartier.', 'warning'); return; }
        const cat = CATS[catId];
        const couleur = cat ? cat.couleur : '#94a3b8';

        if (zoneCtx.mode === 'create') {
            engine.createZone(zoneCtx.points, nom, couleur, { categorie_id: catId });
        } else {
            engine.updateZone(zoneCtx.el.id, nom, couleur, { categorie_id: catId });
        }
        Company.closeModal('dyzZoneModal');
        setTimeout(majListe, 80);
    });
    $('dyzNom').addEventListener('keydown', e => { if (e.key === 'Enter') $('dyzZoneSave').click(); });

    /* ══════════════════ Liste latérale ══════════════════ */
    const listEl = $('dyzList'), countEl = $('dyzCount'), searchEl = $('dyzSearch');

    function zonesTriees() {
        return engine.elements
            .filter(e => e.type === 'zone')
            .slice()
            .sort((a, b) => String(a.title || '').localeCompare(String(b.title || ''), 'fr'));
    }

    function majListe() {
        const q = (searchEl.value || '').toLowerCase().trim();
        const zs = zonesTriees().filter(z => !q || String(z.title || '').toLowerCase().includes(q));
        countEl.textContent = zs.length + (q ? ' / ' + zonesTriees().length : '');

        if (!zs.length) {
            listEl.innerHTML = '<div class="dyz-empty"><i class="fas fa-map" style="display:block;font-size:1.4rem;margin-bottom:.5rem;opacity:.5"></i>' +
                (q ? 'Aucun quartier ne correspond.' : 'Aucun quartier tracé pour l\'instant.') + '</div>';
            return;
        }
        listEl.innerHTML = zs.map(function (z) {
            const cat = CATS[(z.meta && z.meta.categorie_id) || 0];
            return '<div class="dyz-item" data-id="' + esc(z.id) + '">' +
                '<span class="dyz-item-bar" style="background:' + esc(z.color || '#94a3b8') + '"></span>' +
                '<div class="dyz-item-info">' +
                    '<div class="dyz-item-nom">' + esc(z.title || 'Zone') + '</div>' +
                    '<div class="dyz-item-cat">' + esc(cat ? cat.label : 'Sans catégorie') + '</div>' +
                '</div>' +
                '<div class="dyz-item-act">' +
                    (PERMS.edit   ? '<button class="btn btn-secondary btn-icon-sm" data-act="edit" title="Modifier"><i class="fas fa-pen"></i></button>' : '') +
                    (PERMS.delete ? '<button class="btn btn-danger btn-icon-sm" data-act="del" title="Supprimer"><i class="fas fa-trash"></i></button>' : '') +
                '</div></div>';
        }).join('');
    }

    listEl.addEventListener('click', function (e) {
        const item = e.target.closest('.dyz-item'); if (!item) return;
        const z = engine._byId(item.dataset.id); if (!z) return;
        const act = e.target.closest('[data-act]');
        if (act && act.dataset.act === 'del') {
            if (PERMS.delete && confirm('Supprimer le quartier « ' + (z.title || 'Zone') + ' » ?')) {
                engine.deleteZone(z.id); setTimeout(majListe, 60);
            }
            return;
        }
        if (act && act.dataset.act === 'edit') { if (PERMS.edit) openZoneModal({ mode: 'edit', el: z }); return; }
        // Clic simple : centrer la carte sur le quartier
        centrerSur(z);
    });

    listEl.addEventListener('mouseover', function (e) {
        const item = e.target.closest('.dyz-item');
        engine.hoverZoneId = item ? item.dataset.id : null;
        engine.draw();
    });
    listEl.addEventListener('mouseleave', function () { engine.hoverZoneId = null; engine.draw(); });

    function surlignerListe(id) {
        listEl.querySelectorAll('.dyz-item').forEach(function (it) {
            it.classList.toggle('hot', id !== null && String(it.dataset.id) === String(id));
        });
    }

    /** Recentre et zoome sur une zone (marge de 25 %). */
    function centrerSur(z) {
        if (!z.points || !z.points.length) return;
        let x0 = 1, y0 = 1, x1 = 0, y1 = 0;
        z.points.forEach(p => { x0 = Math.min(x0, p[0]); y0 = Math.min(y0, p[1]); x1 = Math.max(x1, p[0]); y1 = Math.max(y1, p[1]); });
        const vp = engine._vp();
        const lw = Math.max((x1 - x0) * engine.W, 1), lh = Math.max((y1 - y0) * engine.H, 1);
        engine.s  = Math.min(vp.w / lw, vp.h / lh) * 0.75;
        engine.tx = vp.w / 2 - ((x0 + x1) / 2) * engine.W * engine.s;
        engine.ty = vp.h / 2 - ((y0 + y1) / 2) * engine.H * engine.s;
        engine._userAdjusted = true;
        engine._applyTransform(); engine.draw();
    }

    searchEl.addEventListener('input', majListe);
    majListe();

    /* ══════════════════ Export / Import JSON ══════════════════ */
    $('dyzExport').addEventListener('click', function () {
        const payload = {
            format: 'dynasty-zones/1',
            exporte_le: new Date().toISOString(),
            categories: Object.values(CATS).map(c => ({
                cle: c.cle, label: c.label, couleur: c.couleur,
                prix_base_location: c.prix_base_location, prix_base_achat: c.prix_base_achat
            })),
            zones: zonesTriees().map(z => ({
                nom: z.title || 'Zone',
                categorie_cle: (CATS[(z.meta && z.meta.categorie_id) || 0] || {}).cle || null,
                points: z.points
            }))
        };
        const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'dynasty-zones-' + new Date().toISOString().slice(0, 10) + '.json';
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        Company.toast(payload.zones.length + ' quartier(s) exporté(s).', 'success');
    });

    const btnImport = $('dyzImport');
    if (btnImport) {
        btnImport.addEventListener('click', () => $('dyzImportFile').click());
        $('dyzImportFile').addEventListener('change', function (e) {
            const f = e.target.files && e.target.files[0]; if (!f) return;
            const fr = new FileReader();
            fr.onload = function () {
                let data;
                try { data = JSON.parse(fr.result); } catch (err) {
                    Company.toast('Fichier JSON illisible.', 'error'); return;
                }
                let zones = Array.isArray(data) ? data : (data.zones || []);
                if (!zones.length) { Company.toast('Aucune zone dans ce fichier.', 'warning'); return; }

                // Plafond de sécurité : un fichier gonflé ne doit pas noyer le serveur.
                const MAX = 200;
                let tronque = false;
                if (zones.length > MAX) { zones = zones.slice(0, MAX); tronque = true; }

                if (!confirm('Importer ' + zones.length + ' quartier(s) ?' +
                    (tronque ? '\n(fichier tronqué au plafond de ' + MAX + ')' : '') +
                    '\n\nIls s\'ajoutent aux quartiers existants, rien n\'est écrasé.')) return;

                // Correspondance par clé de catégorie ; sinon la catégorie active sert de repli.
                const parCle = {};
                Object.values(CATS).forEach(c => { parCle[c.cle] = c.id; });

                const valides = zones.filter(z => Array.isArray(z.points || z.pts) && (z.points || z.pts).length >= 3);

                // Envoi échelonné : createZone déclenche un POST, une rafale de
                // 200 requêtes simultanées serait inutilement brutale.
                valides.forEach(function (z, i) {
                    setTimeout(function () {
                        const catId = parCle[z.categorie_cle] || catActive || 0;
                        const cat   = CATS[catId];
                        engine.createZone(z.points || z.pts,
                            String(z.nom || z.title || 'Zone').slice(0, 150),
                            cat ? cat.couleur : '#94a3b8', { categorie_id: catId });
                        if (i === valides.length - 1) setTimeout(majListe, 400);
                    }, i * 70);
                });
                Company.toast(valides.length + ' quartier(s) en cours d\'import…', valides.length ? 'info' : 'warning');
            };
            fr.readAsText(f);
            e.target.value = '';
        });
    }

    /* Échap : abandonne le tracé en cours (le moteur le gère aussi, on synchronise l'UI). */
    window.addEventListener('keydown', e => { if (e.key === 'Escape') majBoutonsTrace(); });
})();

/* ══════════════════════════════════════════════════════════════════════════
   Onglet Tarification
   Chaque bloc s'édite en place ; l'aperçu du bas recalcule en direct à partir
   des CHAMPS (et non de la base), pour juger d'un changement avant de le
   valider. La formule vient de DynastyPricing, jumeau de dynasty_pricing.php.
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';
    if (!document.getElementById('panel-tarifs')) return;   // permission pricing_view absente

    const DATA  = window.DYZ_DATA;
    const PERMS = DATA.perms;
    const API   = window.DYZ_CFG.apiBase;
    const CSRF  = window.DYZ_CFG.csrf;
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    let G = DATA.grille;                       // état serveur courant
    let usage = DATA.usageCat || {};           // nb de zones par catégorie

    /* ── Appel API ── */
    async function api(action, payload) {
        let r, j;
        try {
            r = await fetch(API + '?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(Object.assign({ csrf: CSRF }, payload || {})),
                credentials: 'same-origin'
            });
            j = await r.json();
        } catch (e) {
            Company.toast('Le serveur n\'a pas répondu.', 'error');
            return { ok: false, status: 0 };
        }
        j.status = r.status;
        if (!j.ok && action.indexOf('delete_category') < 0) {
            Company.toast(j.error || 'Enregistrement refusé.', 'error');
        }
        return j;
    }

    /** Recharge la grille depuis le serveur et redessine les 4 blocs. */
    async function recharger() {
        const j = await api('list', {});
        if (!j.ok) return;
        G = j.data.grille;
        usage = {};
        (j.data.zones || []).forEach(function (z) {
            const c = (z.meta && z.meta.categorie_id) || 0;
            usage[c] = (usage[c] || 0) + 1;
        });
        rendreTout();
    }

    /* ══════════ Rendu des tableaux ══════════ */
    const num = (v) => (v === null || v === undefined || v === '') ? 0 : parseFloat(v);
    const boolAttr = (ok) => ok ? '' : 'disabled';

    function actions(id, type) {
        let h = '';
        if (PERMS.pricing_edit)   h += '<button class="btn btn-primary btn-icon-sm" data-do="save" title="Enregistrer"><i class="fas fa-check"></i></button>';
        if (PERMS.pricing_delete) h += ' <button class="btn btn-danger btn-icon-sm" data-do="del" title="Supprimer"><i class="fas fa-trash"></i></button>';
        return h || '<span style="color:var(--tx-mute);font-size:.7rem">lecture</span>';
    }

    function rendreCats() {
        const rows = Object.values(G.categories || {}).sort((a, b) => a.ordre - b.ordre || a.id - b.id);
        $('tCats').innerHTML = rows.map(c =>
            '<tr data-id="' + c.id + '">' +
            '<td><input type="color" data-f="couleur" value="' + esc(c.couleur) + '" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td><input type="text" data-f="label" value="' + esc(c.label) + '" maxlength="80" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td><input type="number" data-f="prix_base_location" step="0.01" min="0" value="' + num(c.prix_base_location) + '" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td><input type="number" data-f="prix_base_achat" step="0.01" min="0" value="' + num(c.prix_base_achat) + '" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td style="text-align:center"><input type="checkbox" data-f="actif" ' + (c.actif ? 'checked' : '') + ' ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td class="col-act">' + actions(c.id, 'cat') + '</td></tr>').join('')
            || '<tr><td colspan="6" style="padding:1.2rem;text-align:center;color:var(--tx-mute)">Aucune catégorie.</td></tr>';
    }

    function rendreOpts() {
        const rows = Object.values(G.options || {}).sort((a, b) => a.ordre - b.ordre || a.id - b.id);
        $('tOpts').innerHTML = rows.map(o =>
            // data-cle : la clé technique porte l'identité de l'option côté calcul,
            // on ne la reconstruit pas par une recherche sur l'id.
            '<tr data-id="' + o.id + '" data-cle="' + esc(o.cle) + '">' +
            '<td><input type="text" data-f="label" value="' + esc(o.label) + '" maxlength="80" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td><select data-f="type" ' + boolAttr(PERMS.pricing_edit) + '>' +
                '<option value="fixe"' + (o.type === 'fixe' ? ' selected' : '') + '>Fixe</option>' +
                '<option value="multiplicateur"' + (o.type === 'multiplicateur' ? ' selected' : '') + '>Multiplicateur</option>' +
            '</select></td>' +
            '<td><input type="number" data-f="valeur_location" step="0.0001" min="0" value="' + num(o.valeur_location) + '" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td><input type="number" data-f="valeur_achat" step="0.0001" min="0" value="' + num(o.valeur_achat) + '" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td style="text-align:center"><input type="checkbox" data-f="actif" ' + (o.actif ? 'checked' : '') + ' ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td class="col-act">' + actions(o.id, 'opt') + '</td></tr>').join('')
            || '<tr><td colspan="6" style="padding:1.2rem;text-align:center;color:var(--tx-mute)">Aucune option.</td></tr>';
    }

    function rendreGars() {
        const rows = Object.values(G.garages || {}).sort((a, b) => a.ordre - b.ordre || a.places - b.places);
        $('tGars').innerHTML = rows.map(g =>
            '<tr data-id="' + g.id + '">' +
            '<td><input type="number" data-f="places" step="1" min="0" max="999" value="' + (g.places | 0) + '" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td><input type="number" data-f="supplement_location" step="0.01" min="0" value="' + num(g.supplement_location) + '" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td><input type="number" data-f="supplement_achat" step="0.01" min="0" value="' + num(g.supplement_achat) + '" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td><input type="text" data-f="icone" value="' + esc(g.icone || '') + '" maxlength="80" placeholder="fas fa-warehouse" ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td style="text-align:center"><input type="checkbox" data-f="actif" ' + (g.actif ? 'checked' : '') + ' ' + boolAttr(PERMS.pricing_edit) + '></td>' +
            '<td class="col-act">' + actions(g.id, 'gar') + '</td></tr>').join('')
            || '<tr><td colspan="6" style="padding:1.2rem;text-align:center;color:var(--tx-mute)">Aucun palier.</td></tr>';
    }

    function rendreReglages() {
        const c = G.config || {};
        // La taxe est stockée en fraction (0.30) et saisie en pourcentage (30).
        $('cfgTaxe').value   = ((parseFloat(c.taxe) || 0) * 100).toFixed(2).replace(/\.?0+$/, '');
        $('cfgDevise').value = c.devise || '$';
        $('cfgMult').value   = parseFloat(c.multiplicateur_achat) || 1;
        $('cfgBg').value     = c.background || 'atlas';
    }

    function rendreTout() {
        rendreCats(); rendreOpts(); rendreGars(); rendreReglages();
        rendreSimulateur();
        calculer();
        // Les tableaux sont reconstruits à chaque rendu : on (ré)applique les
        // menus déroulants et sélecteurs de couleur personnalisés.
        if (window.DynastyUI) DynastyUI.enhance($('panel-tarifs'));
    }

    /* ── Lecture d'une ligne de tableau ── */
    function lireLigne(tr) {
        const o = { id: parseInt(tr.dataset.id, 10) || 0 };
        tr.querySelectorAll('[data-f]').forEach(function (el) {
            o[el.dataset.f] = (el.type === 'checkbox') ? (el.checked ? 1 : 0)
                            : (el.type === 'number' ? (el.value === '' ? 0 : parseFloat(el.value)) : el.value);
        });
        return o;
    }

    /* ── Marquage « modifié, non enregistré » ── */
    ['tCats', 'tOpts', 'tGars'].forEach(function (id) {
        $(id).addEventListener('input', function (e) {
            const tr = e.target.closest('tr'); if (tr) tr.classList.add('sale');
            calculer();
        });
        $(id).addEventListener('change', calculer);
    });
    ['cfgTaxe', 'cfgDevise', 'cfgMult'].forEach(id => { const el = $(id); if (el) el.addEventListener('input', calculer); });

    /* ══════════ Enregistrement / suppression ══════════ */
    const ACTIONS = {
        tCats: { save: 'pricing_save_category', del: 'pricing_delete_category', nom: 'catégorie' },
        tOpts: { save: 'pricing_save_option',   del: 'pricing_delete_option',   nom: 'option' },
        tGars: { save: 'pricing_save_garage',   del: 'pricing_delete_garage',   nom: 'palier' }
    };

    Object.keys(ACTIONS).forEach(function (tid) {
        $(tid).addEventListener('click', async function (e) {
            const btn = e.target.closest('[data-do]'); if (!btn) return;
            const tr = btn.closest('tr'); if (!tr) return;
            const cfg = ACTIONS[tid];

            if (btn.dataset.do === 'save') {
                if (!PERMS.pricing_edit && !PERMS.pricing_add) return;
                const j = await api(cfg.save, lireLigne(tr));
                if (j.ok) { Company.toast('Enregistré.', 'success'); await recharger(); }
                return;
            }
            if (btn.dataset.do === 'del') {
                if (!PERMS.pricing_delete) return;
                const id = parseInt(tr.dataset.id, 10) || 0;
                if (!confirm('Supprimer cette ' + cfg.nom + ' ?')) return;
                let j = await api(cfg.del, { id: id });

                // 409 : catégorie encore utilisée → on propose une réaffectation.
                if (!j.ok && j.status === 409 && Array.isArray(j.zones)) {
                    const autres = Object.values(G.categories).filter(c => c.id !== id);
                    if (!autres.length) {
                        Company.toast('Impossible : c\'est la dernière catégorie et des zones l\'utilisent.', 'error');
                        return;
                    }
                    const liste = autres.map((c, i) => '  ' + (i + 1) + '. ' + c.label).join('\n');
                    const rep = prompt(
                        j.zones.length + ' quartier(s) utilisent cette catégorie :\n' +
                        j.zones.slice(0, 8).map(z => '  · ' + z.nom).join('\n') +
                        (j.zones.length > 8 ? '\n  · …' : '') +
                        '\n\nVers quelle catégorie les basculer ?\n' + liste + '\n\nNuméro :', '1');
                    if (rep === null) return;
                    const cible = autres[(parseInt(rep, 10) || 0) - 1];
                    if (!cible) { Company.toast('Choix invalide.', 'warning'); return; }
                    j = await api(cfg.del, { id: id, reassign_to: cible.id });
                    if (j.ok) Company.toast(j.data.reassigned + ' quartier(s) basculé(s) vers ' + cible.label + '.', 'success');
                } else if (!j.ok) {
                    Company.toast(j.error || 'Suppression refusée.', 'error');
                    return;
                } else {
                    Company.toast('Supprimé.', 'success');
                }
                await recharger();
            }
        });
    });

    /* ── Ajouts ── */
    async function ajouter(action, payload) {
        const j = await api(action, payload);
        if (j.ok) { Company.toast('Ajouté.', 'success'); await recharger(); }
    }
    if ($('addCat')) $('addCat').addEventListener('click', () => {
        const l = prompt('Libellé de la nouvelle catégorie :', 'Nouveau quartier');
        if (!l) return;
        ajouter('pricing_save_category', {
            id: 0, label: l, couleur: '#94a3b8', prix_base_location: 0, prix_base_achat: 0,
            ordre: Object.keys(G.categories).length + 1, actif: 1
        });
    });
    if ($('addOpt')) $('addOpt').addEventListener('click', () => {
        const l = prompt('Libellé de la nouvelle option :', 'Nouvelle option');
        if (!l) return;
        ajouter('pricing_save_option', {
            id: 0, label: l, type: 'fixe', valeur_location: 0, valeur_achat: 0,
            ordre: Object.keys(G.options).length + 1, actif: 1
        });
    });
    if ($('addGar')) $('addGar').addEventListener('click', () => {
        const p = prompt('Nombre de places du nouveau palier :', '8');
        if (p === null) return;
        ajouter('pricing_save_garage', {
            id: 0, places: parseInt(p, 10) || 0, supplement_location: 0, supplement_achat: 0,
            ordre: parseInt(p, 10) || 0, actif: 1
        });
    });

    /* ── Réglages globaux ── */
    if ($('saveCfg')) $('saveCfg').addEventListener('click', async function () {
        const j = await api('pricing_save_config', {
            taxe: (parseFloat($('cfgTaxe').value) || 0) / 100,   // saisi en %, stocké en fraction
            devise: $('cfgDevise').value,
            multiplicateur_achat: parseFloat($('cfgMult').value) || 1,
            background: $('cfgBg').value
        });
        if (j.ok) { Company.toast('Réglages enregistrés.', 'success'); await recharger(); }
    });

    /* ══════════ Aperçu du calcul ══════════ */
    let simMode = 'location', simCat = 0, simGar = 0;
    const simOptsActives = new Set();

    /**
     * Reconstruit une grille à partir des CHAMPS du formulaire : c'est tout
     * l'intérêt de l'aperçu, juger d'un changement avant de l'enregistrer.
     */
    function grilleDepuisFormulaires() {
        const cfg = { categories: {}, options: {}, garages: {}, config: {} };
        $('tCats').querySelectorAll('tr[data-id]').forEach(function (tr) {
            const r = lireLigne(tr); if (!r.actif) return;
            const src = G.categories[r.id] || {};
            cfg.categories[r.id] = Object.assign({}, src, r);
        });
        $('tOpts').querySelectorAll('tr[data-id]').forEach(function (tr) {
            const r = lireLigne(tr); if (!r.actif) return;
            const cle = tr.dataset.cle || ('option_' + r.id);
            cfg.options[cle] = Object.assign({}, G.options[cle] || {}, r, { cle: cle });
        });
        $('tGars').querySelectorAll('tr[data-id]').forEach(function (tr) {
            const r = lireLigne(tr); if (!r.actif) return;
            cfg.garages[r.places] = r;
        });
        cfg.config = {
            taxe: String((parseFloat($('cfgTaxe').value) || 0) / 100),
            devise: $('cfgDevise').value || '$',
            multiplicateur_achat: String(parseFloat($('cfgMult').value) || 1)
        };
        return cfg;
    }

    function rendreSimulateur() {
        const cfg = grilleDepuisFormulaires();
        const cats = Object.values(cfg.categories);
        const opts = Object.values(cfg.options);
        const gars = Object.values(cfg.garages).sort((a, b) => a.places - b.places);

        if (!cats.some(c => c.id === simCat)) simCat = cats.length ? cats[0].id : 0;
        if (!gars.some(g => g.places === simGar)) simGar = gars.length ? gars[0].places : 0;

        $('simCats').innerHTML = cats.map(c =>
            '<button class="dyz-chip' + (c.id === simCat ? ' active' : '') + '" data-cat="' + c.id + '">' +
            '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + esc(c.couleur) + ';margin-right:.35rem"></span>' +
            esc(c.label) + '</button>').join('') || '<span style="color:var(--tx-mute);font-size:.74rem">aucune</span>';

        $('simOpts').innerHTML = opts.map(o =>
            '<button class="dyz-chip' + (simOptsActives.has(o.cle) ? ' active' : '') + '" data-opt="' + esc(o.cle) + '">' +
            esc(o.label) + '</button>').join('') || '<span style="color:var(--tx-mute);font-size:.74rem">aucune</span>';

        $('simGars').innerHTML = gars.map(g =>
            '<button class="dyz-chip' + (g.places === simGar ? ' active' : '') + '" data-gar="' + g.places + '">' +
            g.places + ' pl.</button>').join('') || '<span style="color:var(--tx-mute);font-size:.74rem">aucun</span>';
    }

    $('simMode').addEventListener('click', function (e) {
        const b = e.target.closest('[data-mode]'); if (!b) return;
        simMode = b.dataset.mode;
        this.querySelectorAll('button').forEach(x => x.classList.toggle('active', x === b));
        calculer();
    });
    $('simCats').addEventListener('click', e => { const b = e.target.closest('[data-cat]'); if (!b) return; simCat = parseInt(b.dataset.cat, 10); rendreSimulateur(); calculer(); });
    $('simGars').addEventListener('click', e => { const b = e.target.closest('[data-gar]'); if (!b) return; simGar = parseInt(b.dataset.gar, 10); rendreSimulateur(); calculer(); });
    $('simOpts').addEventListener('click', e => {
        const b = e.target.closest('[data-opt]'); if (!b) return;
        const k = b.dataset.opt;
        simOptsActives.has(k) ? simOptsActives.delete(k) : simOptsActives.add(k);
        rendreSimulateur(); calculer();
    });

    function calculer() {
        const cfg = grilleDepuisFormulaires();
        const r = DynastyPricing.compute(cfg, {
            mode: simMode, categorie_id: simCat,
            options: Array.from(simOptsActives), garage_places: simGar
        });
        const f = (v) => DynastyPricing.format(v, r.devise);
        $('fBase').textContent  = f(r.base);
        $('fOpts').textContent  = f(r.options);
        $('fGar').textContent   = f(r.garage);
        $('fST').textContent    = f(r.sous_total);
        $('fTaxeLbl').textContent = 'Taxe : ' + (r.taxe_taux * 100).toFixed(
            (r.taxe_taux * 100) % 1 === 0 ? 0 : 2) + ' %';
        $('fTaxe').textContent  = f(r.taxe_montant);
        $('fTotal').textContent = f(r.total);

        // Contrôle de non-régression affiché en clair : le cas de la maquette.
        const ctl = DynastyPricing.compute(cfg, { mode: 'location', categorie_id: simCat, options: [], garage_places: 0 });
        const el = $('dyzControle');
        const base = cfg.categories[simCat] ? parseFloat(cfg.categories[simCat].prix_base_location) : 0;
        if (base === 1000 && Math.abs(r.taxe_taux - 0.30) < 1e-9) {
            const ok = Math.abs(ctl.total - 1300) < 0.01;
            el.className = 'dyz-controle ' + (ok ? 'ok' : 'ko');
            el.textContent = ok ? '✓ Contrôle maquette : 1000 × 1,30 = 1 300 $'
                                : '✗ Contrôle maquette attendu 1 300 $, obtenu ' + f(ctl.total);
        } else {
            el.className = 'dyz-controle';
            el.textContent = 'Base sans option ni garage : ' + f(ctl.total);
        }
    }

    rendreTout();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
