<?php
/**
 * Dynasty · Site public — Catalogue des biens disponibles.
 * Lecture seule, non authentifié. Filtres par catégorie de quartier
 * (via zone_id, repli sur le texte `quartier`), type de bien et prix.
 */
require_once __DIR__ . '/_bootstrap.php';

$db = dy_db();
dynasty_zones_bootstrap($db);

$grille   = dynasty_pricing_load($db, true);
$devise   = (string) dynasty_pricing_setting($grille, 'devise', '$');
$hasZone  = dy_has_column('dynasty_biens', 'zone_id');

$TYPES = ['appartement'=>'Appartement','maison'=>'Maison','villa'=>'Villa','bureau'=>'Bureau',
          'local_commercial'=>'Local Commercial','terrain'=>'Terrain','parking'=>'Parking','studio'=>'Studio'];
$ICONS = ['appartement'=>'fa-building','maison'=>'fa-house','villa'=>'fa-landmark','bureau'=>'fa-briefcase',
          'local_commercial'=>'fa-store','terrain'=>'fa-mountain-sun','parking'=>'fa-square-parking','studio'=>'fa-door-open'];

/* ── Chargement des biens disponibles ── */
$biens = [];
try {
    // La jointure sur la zone/catégorie n'est possible que si la colonne existe.
    if ($hasZone) {
        $sql = "SELECT b.*, z.nom AS zone_nom, z.categorie_id AS zone_cat
                  FROM dynasty_biens b
                  LEFT JOIN dynasty_zones z ON z.id = b.zone_id
                 WHERE b.statut = 'disponible'
                   AND (b.disponible_vente = 1 OR b.disponible_location = 1)
                 ORDER BY b.created_at DESC";
    } else {
        $sql = "SELECT b.* FROM dynasty_biens b
                 WHERE b.statut = 'disponible'
                   AND (b.disponible_vente = 1 OR b.disponible_location = 1)
                 ORDER BY b.created_at DESC";
    }
    $biens = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[Dynasty Public] catalogue: ' . $e->getMessage());
    $biens = [];
}

/* Table nom de quartier (minuscule) → catégorie, pour rapprocher les biens
   dont le zone_id est nul mais dont le texte `quartier` colle à une zone. */
$zonesParNom = [];
foreach (dynasty_zones_load($db, $grille['categories']) as $z) {
    $zonesParNom[mb_strtolower(trim($z['title']))] = (int) ($z['meta']['categorie_id'] ?? 0);
}

/** Catégorie effective d'un bien : d'abord zone_id, sinon rapprochement par nom. */
$catDuBien = function (array $b) use ($zonesParNom): int {
    if (!empty($b['zone_cat'])) return (int) $b['zone_cat'];
    $q = mb_strtolower(trim((string) ($b['quartier'] ?? '')));
    return $zonesParNom[$q] ?? 0;
};

/* Bornes de prix pour le curseur (sur l'ensemble avant filtrage). */
$prixMax = 0;
foreach ($biens as $b) {
    $prixMax = max($prixMax, (float) ($b['prix_vente'] ?? 0),
                   (float) ($b['prix_location_semaine'] ?? 0) * 4,
                   (float) ($b['prix_location_mois'] ?? 0));
}
$prixMax = $prixMax > 0 ? (int) ceil($prixMax / 1000) * 1000 : 100000;

$DY_ACTIVE     = 'catalogue';
$DY_PAGE_TITLE = 'Catalogue · Dynasty Immobilier';
require __DIR__ . '/_header.php';
?>

<main class="dy-container">

    <section class="dy-hero">
        <h1>Le <span class="g">catalogue</span> Dynasty</h1>
        <p>Tous les biens actuellement disponibles à la location ou à la vente à Lysoria.</p>
    </section>

    <div class="dy-filters">
        <div class="dy-field">
            <label for="fCat">Quartier</label>
            <select id="fCat">
                <option value="">Tous les quartiers</option>
                <?php foreach ($grille['categories'] as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>"><?php echo dy_esc($c['label']); ?></option>
                <?php endforeach; ?>
                <option value="0">Non catégorisé</option>
            </select>
        </div>
        <div class="dy-field">
            <label for="fType">Type de bien</label>
            <select id="fType">
                <option value="">Tous les types</option>
                <?php foreach ($TYPES as $k => $lbl): ?>
                <option value="<?php echo dy_esc($k); ?>"><?php echo dy_esc($lbl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="dy-field">
            <label for="fTrans">Transaction</label>
            <select id="fTrans">
                <option value="">Location &amp; vente</option>
                <option value="location">Location</option>
                <option value="vente">Vente</option>
            </select>
        </div>
        <div class="dy-field">
            <label for="fPrix">Prix max : <span id="fPrixVal">—</span></label>
            <input type="range" id="fPrix" min="0" max="<?php echo $prixMax; ?>" step="1000" value="<?php echo $prixMax; ?>" style="min-width:200px">
        </div>
        <div class="dy-count" id="dyCount"></div>
    </div>

    <div class="dy-goods" id="dyGoods"></div>
</main>

<?php
/* Sérialisation légère pour le filtrage client. */
$out = [];
foreach ($biens as $b) {
    $cat = $catDuBien($b);
    $out[] = [
        'ref'      => (string) ($b['reference'] ?? ''),
        'type'     => (string) ($b['type_bien'] ?? ''),
        'titre'    => (string) ($b['titre'] ?? ''),
        'desc'     => mb_substr(trim(strip_tags((string) ($b['description'] ?? ''))), 0, 220),
        'adresse'  => (string) ($b['adresse'] ?? ''),
        'quartier' => (string) ($b['zone_nom'] ?? $b['quartier'] ?? ''),
        'cat'      => $cat,
        'surface'  => (float) ($b['surface'] ?? 0),
        'pieces'   => (int) ($b['pieces'] ?? 0),
        'chambres' => (int) ($b['chambres'] ?? 0),
        'vente'    => (int) ($b['disponible_vente'] ?? 0) === 1,
        'location' => (int) ($b['disponible_location'] ?? 0) === 1,
        'prix_vente'   => (float) ($b['prix_vente'] ?? 0),
        'prix_sem'     => (float) ($b['prix_location_semaine'] ?? 0),
        'prix_mois'    => (float) ($b['prix_location_mois'] ?? 0),
    ];
}
$catMap = [];
foreach ($grille['categories'] as $c) {
    $catMap[(int)$c['id']] = ['label' => $c['label'], 'couleur' => $c['couleur']];
}
?>
<script>
window.DY_CAT = <?php echo json_encode((object) $catMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
window.DY_BIENS = <?php echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
window.DY_TYPES = <?php echo json_encode((object) $TYPES, JSON_UNESCAPED_UNICODE); ?>;
window.DY_ICONS = <?php echo json_encode((object) $ICONS, JSON_UNESCAPED_UNICODE); ?>;
window.DY_DEVISE = <?php echo json_encode($devise); ?>;
</script>
<script src="<?php echo SITE_URL; ?>/assets/js/dynasty/dynasty-pricing.js?v=<?php echo @filemtime(ROOT_PATH . '/assets/js/dynasty/dynasty-pricing.js') ?: '1'; ?>"></script>
<script src="<?php echo dy_pub_url('assets/catalogue.js'); ?>?v=1"></script>

<?php require __DIR__ . '/_footer.php'; ?>
