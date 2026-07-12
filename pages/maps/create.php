<?php
/**
 * pages/maps/create.php — Création d'une carte de faction :
 * sélection du fond → formulaire (titre, tag, permission) → éditeur.
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

$faction = strtoupper(trim((string) ($_GET['faction'] ?? $_POST['faction'] ?? '')));
if ($faction === ''
    || !in_array($faction, maps_service_faction_keys(), true)
    || !maps_user_can($db, $uid, $faction, 'create')) {
    redirect(SITE_URL . '/pages/maps/index.php');
}

$allMeta     = maps_faction_meta($db);
$factionMeta = $allMeta[$faction] ?? ['name' => $faction, 'color' => '#10b981'];
$csrf        = mdt_map_csrf();
$error       = '';

/* ─── Soumission ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mdt_map_verify_csrf((string) ($_POST['csrf'] ?? ''))) {
        $error = 'Session expirée, réessayez.';
    } else {
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 150);
        $bg    = maps_background_key((string) ($_POST['background'] ?? 'atlas'));
        $vis   = in_array(($_POST['visibility'] ?? ''), ['draft', 'public', 'private'], true) ? $_POST['visibility'] : 'draft';
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        if ($title === '') {
            $error = 'Le titre est requis.';
        } else {
            if ($tagId > 0) {
                $chk = $db->prepare("SELECT COUNT(*) FROM mdt_map_tags WHERE id = ?");
                $chk->execute([$tagId]);
                if (!(int) $chk->fetchColumn()) {
                    $tagId = 0;
                }
            }
            $creatorName = function_exists('mdt_session_display_name') ? mdt_session_display_name() : 'Agent';
            $db->prepare("INSERT INTO mdt_maps (scope, faction_key, title, tag_id, background, visibility, creator_id, creator_name)
                          VALUES ('faction', ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$faction, $title, $tagId ?: null, $bg, $vis, $uid, $creatorName]);
            redirect(SITE_URL . '/pages/maps/editor.php?id=' . (int) $db->lastInsertId());
        }
    }
}

$tags = [];
try {
    $tags = $db->query("SELECT id, nom, color FROM mdt_map_tags ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$mapsPageTitle = 'Nouvelle carte — ' . ($factionMeta['name'] ?? $faction);
$mapsActiveNav = $faction;
$backgrounds   = maps_background_defs();

define('MDT_MAPS_FRONT', 1);
include __DIR__ . '/includes/front_header.php';
?>

<div class="create-wrap">
    <div class="maps-topline">
        <div>
            <div class="maps-eyebrow"><?php echo htmlspecialchars($faction); ?> · Nouvelle carte</div>
            <h1 class="maps-h1">Créer une map</h1>
            <p class="maps-sub">Choisissez un fond de carte, puis renseignez les informations.</p>
        </div>
        <a href="<?php echo SITE_URL; ?>/pages/maps/index.php?faction=<?php echo urlencode($faction); ?>" class="mbtn mbtn-ghost">
            <i class="fa-solid fa-arrow-left"></i> Retour
        </a>
    </div>

    <?php if ($error): ?>
    <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:12px 16px;border-radius:10px;margin-bottom:20px;">
        <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="createForm">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
        <input type="hidden" name="faction" value="<?php echo htmlspecialchars($faction, ENT_QUOTES); ?>">
        <input type="hidden" name="background" id="bgInput" value="atlas">
        <input type="hidden" name="visibility" id="visInput" value="draft">

        <div class="mlabel">Fond de carte</div>
        <div class="bg-picker">
            <?php $first = true; foreach ($backgrounds as $key => $b): ?>
            <div class="bg-option <?php echo $first ? 'selected' : ''; ?>" data-bg="<?php echo htmlspecialchars($key); ?>" onclick="selectBg(this)">
                <div class="bg-frame" style="background-image:url('<?php echo htmlspecialchars(maps_background_thumb($key)); ?>')">
                    <div class="bg-check"><span><i class="fa-solid fa-check"></i></span></div>
                </div>
                <div class="bg-name"><?php echo htmlspecialchars($b['label']); ?></div>
            </div>
            <?php $first = false; endforeach; ?>
        </div>

        <div class="mform">
            <div class="mfield">
                <label class="mlabel" for="titleInput">Titre</label>
                <input class="minput" id="titleInput" name="title" maxlength="150" placeholder="Ex. Opération Vespucci" required autocomplete="off">
            </div>

            <div class="mfield">
                <label class="mlabel" for="tagSelect">Tag</label>
                <select class="mselect" id="tagSelect" name="tag_id">
                    <option value="0">— Aucun —</option>
                    <?php foreach ($tags as $t): ?>
                    <option value="<?php echo (int) $t['id']; ?>"><?php echo htmlspecialchars($t['nom']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mfield">
                <div class="mlabel">Permission</div>
                <div class="vis-row">
                    <div class="vis-opt selected" data-vis="draft" onclick="selectVis(this)">
                        <i class="fa-solid fa-pen-ruler"></i>
                        <div class="vis-t">Brouillon</div>
                        <div class="vis-d">Visible par vous seul</div>
                    </div>
                    <div class="vis-opt" data-vis="public" onclick="selectVis(this)">
                        <i class="fa-solid fa-globe"></i>
                        <div class="vis-t">Public</div>
                        <div class="vis-d">Visible selon les accès</div>
                    </div>
                    <div class="vis-opt" data-vis="private" onclick="selectVis(this)">
                        <i class="fa-solid fa-lock"></i>
                        <div class="vis-t">Privé</div>
                        <div class="vis-d">Visible par vous seul</div>
                    </div>
                </div>
            </div>

            <button type="submit" class="mbtn mbtn-primary" style="width:100%;justify-content:center;margin-top:8px;">
                <i class="fa-solid fa-arrow-right"></i> Créer et ouvrir l'éditeur
            </button>
        </div>
    </form>
</div>

<?php
$mapsExtraJs = <<<'JS'
<script>
function selectBg(el){
    document.querySelectorAll('.bg-option').forEach(o=>o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('bgInput').value = el.dataset.bg;
}
function selectVis(el){
    document.querySelectorAll('.vis-opt').forEach(o=>o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('visInput').value = el.dataset.vis;
}
</script>
JS;
include __DIR__ . '/includes/front_footer.php';
