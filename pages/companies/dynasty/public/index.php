<?php
/**
 * Dynasty · Site public — Calculateur immobilier + carte des quartiers.
 * Lecture seule, non authentifié. Toutes les données sont injectées au
 * chargement (aucune requête récurrente, aucune charge par visiteur).
 */
require_once __DIR__ . '/_bootstrap.php';

$db = dy_db();
dynasty_zones_bootstrap($db);

/* Grille (actifs uniquement) + zones, injectées en un bloc JSON. */
$grille = dynasty_pricing_load($db, true);
$zones  = dynasty_zones_load($db, $grille['categories']);
$bgKey  = (string) dynasty_pricing_setting($grille, 'background', 'atlas');

$DY_ACTIVE = 'calc';
require __DIR__ . '/_header.php';

$dataJson = json_encode([
    'grille'            => dynasty_pricing_for_js($grille),
    'background'        => $bgKey,
    'backgroundSources' => maps_background_sources(),
    'zones'            => $zones,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
?>

<main class="dy-container">

    <section class="dy-hero">
        <h1>Estimez votre <span class="g">bien immobilier</span></h1>
        <p>Choisissez un quartier sur la carte, ajustez les options, et obtenez une estimation
           instantanée du loyer ou du prix d'achat à Lysoria.</p>
    </section>

    <?php if (empty($grille['categories'])): ?>
        <div class="dy-panel" style="text-align:center;padding:3rem 1rem;color:var(--tx-3)">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:1.8rem;color:var(--tx-mute);display:block;margin-bottom:.7rem"></i>
            La grille tarifaire n'est pas encore configurée. Revenez bientôt.
        </div>
    <?php else: ?>

    <section class="dy-calc">

        <!-- ── Colonne carte ── -->
        <div class="dy-mapcard">
            <div class="dy-mapcard-head">
                <h2><i class="fa-solid fa-map-location-dot" style="color:var(--dy)"></i> Carte des quartiers</h2>
                <span class="hint"><i class="fa-solid fa-arrow-pointer"></i> Cliquez une zone pour la sélectionner</span>
            </div>
            <div class="dy-stage" id="dyStage">
                <div class="dy-plane" id="dyPlane"><img id="dyImg" alt="Carte de Lysoria" src=""></div>
                <canvas class="dy-canvas" id="dyCanvas"></canvas>
                <div class="dy-overlay" id="dyOverlay"></div>
                <div class="dy-tip" id="dyTip"></div>
                <div class="dy-zoom">
                    <button id="dyZoomIn" title="Zoom avant"><i class="fa-solid fa-plus"></i></button>
                    <button id="dyZoomOut" title="Zoom arrière"><i class="fa-solid fa-minus"></i></button>
                    <button id="dyZoomFit" title="Recentrer"><i class="fa-solid fa-expand"></i></button>
                </div>
            </div>
            <div class="dy-legend" id="dyLegend">
                <?php foreach ($grille['categories'] as $c): ?>
                <span><i style="background:<?php echo dy_esc($c['couleur']); ?>"></i><?php echo dy_esc($c['label']); ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Colonne configuration ── -->
        <div class="dy-config">

            <div class="dy-panel">
                <h3>Type de transaction</h3>
                <div class="dy-seg" id="dyMode">
                    <button class="active" data-mode="location"><i class="fa-solid fa-key"></i> Location</button>
                    <button data-mode="achat"><i class="fa-solid fa-house-circle-check"></i> Achat</button>
                </div>
            </div>

            <div class="dy-panel">
                <h3>Quartier</h3>
                <div class="dy-cats" id="dyCats"></div>
            </div>

            <?php if (!empty($grille['options'])): ?>
            <div class="dy-panel" id="dyOptsPanel">
                <h3>Options</h3>
                <div class="dy-opts" id="dyOpts"></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($grille['garages'])): ?>
            <div class="dy-panel">
                <h3>Capacité du garage</h3>
                <div class="dy-gars" id="dyGars"></div>
            </div>
            <?php endif; ?>

            <!-- Barre de facturation -->
            <div class="dy-facture">
                <div class="dy-fac-head"><span class="ico"><i class="fa-solid fa-dollar-sign"></i></span> Estimation</div>
                <div class="dy-fac-l"><span>Base</span><b id="dyFBase">—</b></div>
                <div class="dy-fac-l"><span>Options</span><b id="dyFOpts">—</b></div>
                <div class="dy-fac-l"><span>Garage</span><b id="dyFGar">—</b></div>
                <div class="dy-fac-sep"></div>
                <div class="dy-fac-l"><span>Sous-total</span><b id="dyFST">—</b></div>
                <div class="dy-fac-l"><span id="dyFTaxeLbl">Taxe</span><b id="dyFTaxe">—</b></div>
                <div class="dy-fac-sep"></div>
                <div class="dy-fac-total-l">Montant estimé</div>
                <div class="dy-fac-total" id="dyFTotal">—</div>
            </div>
        </div>
    </section>

    <?php endif; ?>
</main>

<script>window.DY_DATA = <?php echo $dataJson; ?>;</script>
<script src="<?php echo SITE_URL; ?>/assets/js/maps/map-engine.js?v=<?php echo @filemtime(ROOT_PATH . '/assets/js/maps/map-engine.js') ?: '1'; ?>"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/dynasty/dynasty-pricing.js?v=<?php echo @filemtime(ROOT_PATH . '/assets/js/dynasty/dynasty-pricing.js') ?: '1'; ?>"></script>
<script src="<?php echo dy_pub_url('assets/calc.js'); ?>?v=1"></script>

<?php require __DIR__ . '/_footer.php'; ?>
