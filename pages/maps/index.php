<?php
/**
 * pages/maps/index.php — Panel Cartes (accueil + galerie d'une faction).
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
$activeKeys  = maps_active_faction_keys($db);

$faction     = strtoupper(trim((string) ($_GET['faction'] ?? '')));
$mode        = 'home';
$factionMeta = null;
$canCreate   = false;

if ($faction !== ''
    && in_array($faction, maps_service_faction_keys(), true)
    && in_array($faction, $activeKeys, true)
    && maps_user_can($db, $uid, $faction, 'view')) {
    $mode        = 'faction';
    $allMeta     = maps_faction_meta($db);
    $factionMeta = $allMeta[$faction] ?? null;
    $canCreate   = maps_user_can($db, $uid, $faction, 'create');
}

$mapsPageTitle = ($mode === 'faction' && $factionMeta) ? ('Map ' . $factionMeta['name']) : 'Cartes';
$mapsActiveNav = ($mode === 'faction') ? $faction : '';
$csrf          = mdt_map_csrf();

define('MDT_MAPS_FRONT', 1);
include __DIR__ . '/includes/front_header.php';
?>

<?php if ($mode === 'home'): ?>
    <div class="maps-topline">
        <div>
            <div class="maps-eyebrow">Services · Cartographie</div>
            <h1 class="maps-h1">Système de Cartes</h1>
            <p class="maps-sub">Créez, partagez et collaborez sur des cartes interactives de Los Santos.</p>
        </div>
    </div>

    <div class="maps-grid">
        <a href="<?php echo SITE_URL; ?>/pages/maps/editor.php?special=commune" class="dest-card" style="--c-color:#3b82f6">
            <div class="dest-badge">Temps réel</div>
            <div class="dest-ico"><i class="fa-solid fa-city"></i></div>
            <div>
                <div class="dest-name">Map Commune</div>
                <div class="dest-desc">Carte publique collaborative en temps réel.</div>
            </div>
        </a>
        <a href="<?php echo SITE_URL; ?>/pages/maps/editor.php?special=gang" class="dest-card" style="--c-color:#8b5cf6">
            <div class="dest-badge">Zones</div>
            <div class="dest-ico"><i class="fa-solid fa-users-viewfinder"></i></div>
            <div>
                <div class="dest-name">Map Gang</div>
                <div class="dest-desc">Carte collaborative avec zones de territoire.</div>
            </div>
        </a>

        <?php foreach ($navFactions as $key => $m): ?>
        <a href="<?php echo SITE_URL; ?>/pages/maps/index.php?faction=<?php echo urlencode($key); ?>"
           class="dest-card" style="--c-color:<?php echo htmlspecialchars($m['color']); ?>">
            <div class="dest-ico">
                <?php if (!empty($m['logo'])): ?>
                    <img src="<?php echo htmlspecialchars($m['logo']); ?>" alt="" referrerpolicy="no-referrer">
                <?php else: ?>
                    <i class="fa-solid fa-<?php echo htmlspecialchars($m['icon']); ?>"></i>
                <?php endif; ?>
            </div>
            <div>
                <div class="dest-name">Map <?php echo htmlspecialchars($m['name']); ?></div>
                <div class="dest-desc">Cartes de la faction <?php echo htmlspecialchars($key); ?>.</div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

<?php else: /* mode faction */ ?>
    <div class="maps-topline">
        <div>
            <div class="maps-eyebrow"><?php echo htmlspecialchars($faction); ?> · Cartes de faction</div>
            <h1 class="maps-h1">Map <?php echo htmlspecialchars($factionMeta['name'] ?? $faction); ?></h1>
            <p class="maps-sub">Consultez, modifiez ou créez des cartes tactiques de votre faction.</p>
        </div>
        <?php if ($canCreate): ?>
        <a href="<?php echo SITE_URL; ?>/pages/maps/create.php?faction=<?php echo urlencode($faction); ?>" class="mbtn mbtn-primary">
            <i class="fa-solid fa-plus"></i> Créer une map
        </a>
        <?php endif; ?>
    </div>

    <div id="galGrid" class="gal-grid">
        <div class="maps-empty" style="grid-column:1/-1;"><i class="fa-solid fa-spinner fa-spin"></i><p>Chargement des cartes…</p></div>
    </div>
<?php endif; ?>

<?php
if ($mode === 'faction') {
    $jsFaction = json_encode($faction);
    $jsSite    = json_encode(SITE_URL);
    $jsCsrf    = json_encode($csrf);
    $mapsExtraJs = <<<JS
<script>
(function () {
    const FACTION = $jsFaction, SITE = $jsSite, CSRF = $jsCsrf;
    const API = SITE + '/pages/maps/api.php';
    const grid = document.getElementById('galGrid');

    function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
    function xhr(){ return { 'X-Requested-With':'XMLHttpRequest' }; }

    function fmtDate(s){
        if(!s) return '—';
        const d = new Date(s.replace(' ','T'));
        if(isNaN(d)) return esc(s);
        return d.toLocaleDateString('fr-FR') + ' · ' + d.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
    }

    function load(){
        fetch(API + '?action=list_maps&faction=' + encodeURIComponent(FACTION), {headers:xhr(),credentials:'same-origin'})
            .then(r=>r.json()).then(j=>{
                if(!j.ok){ grid.innerHTML = '<div class="maps-empty" style="grid-column:1/-1;"><i class="fa-solid fa-lock"></i><p>'+esc(j.error||'Accès refusé')+'</p></div>'; return; }
                render(j.data.maps||[]);
            }).catch(()=>{ grid.innerHTML = '<div class="maps-empty" style="grid-column:1/-1;"><i class="fa-solid fa-triangle-exclamation"></i><p>Erreur de chargement</p></div>'; });
    }

    function render(maps){
        if(!maps.length){
            grid.innerHTML = '<div class="maps-empty" style="grid-column:1/-1;"><i class="fa-solid fa-map"></i><p>Aucune carte pour le moment.</p></div>';
            return;
        }
        grid.innerHTML = maps.map(m => {
            const tag = m.tag ? '<span class="gal-tag" style="color:'+esc(m.tag.color)+'"><span style="width:7px;height:7px;border-radius:50%;background:'+esc(m.tag.color)+'"></span>'+esc(m.tag.nom)+'</span>' : '';
            const editBtn = m.can_edit ? '<a class="mbtn mbtn-ghost mbtn-sm" href="'+SITE+'/pages/maps/editor.php?id='+m.id+'"><i class="fa-solid fa-pen"></i> Modifier</a>' : '';
            const delBtn  = m.can_delete ? '<button class="mbtn mbtn-danger mbtn-sm" onclick="mapsDelete('+m.id+',this)"><i class="fa-solid fa-trash"></i></button>' : '';
            return '<div class="gal-card">' +
                '<div class="gal-thumb" style="background-image:url('+esc(m.thumb)+')">' +
                    tag +
                    '<span class="gal-vis '+esc(m.visibility)+'">'+ (m.visibility==='draft'?'Brouillon':(m.visibility==='private'?'Privé':'Public')) +'</span>' +
                    '<span class="gal-bg">'+esc(m.background_label)+'</span>' +
                '</div>' +
                '<div class="gal-body">' +
                    '<div class="gal-title">'+esc(m.title)+'</div>' +
                    '<div class="gal-meta">' +
                        '<span><i class="fa-solid fa-user"></i> '+esc(m.creator_name)+'</span>' +
                        '<span><i class="fa-solid fa-clock"></i> '+fmtDate(m.updated_at)+'</span>' +
                    '</div>' +
                    '<div class="gal-actions">' +
                        '<a class="mbtn mbtn-ghost mbtn-sm" href="'+SITE+'/pages/maps/editor.php?id='+m.id+'&view=1"><i class="fa-solid fa-eye"></i> Consulter</a>' +
                        editBtn + delBtn +
                    '</div>' +
                '</div>' +
            '</div>';
        }).join('');
    }

    window.mapsDelete = function(id, el){
        if(!confirm('Supprimer définitivement cette carte ?')) return;
        fetch(API + '?action=delete_map', {method:'POST', headers:Object.assign({'Content-Type':'application/json'},xhr()), credentials:'same-origin', body:JSON.stringify({id:id, csrf:CSRF})})
            .then(r=>r.json()).then(j=>{ if(!j.ok){ alert(j.error||'Erreur'); return; } load(); });
    };

    load();
})();
</script>
JS;
}
include __DIR__ . '/includes/front_footer.php';
