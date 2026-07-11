<?php
// ── Auto-detect config path (works from any subdir level) ────────────────
$__cfgLevels = [2, 3];
foreach ($__cfgLevels as $__n) {
    $__cfgPath = dirname(__DIR__, $__n) . '/config/config.php';
    if (file_exists($__cfgPath)) { require_once $__cfgPath; break; }
}
if (!function_exists('requireLogin')) die('Config not found');
requireLogin();
if (!in_array($_SESSION['user_faction'] ?? '', ['LSPD','LSSD','DOJ']) &&
    !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();

// Panel name
$__lspdName = 'LSPD'; $__lspdSub = 'Los Santos Police Department';
try { $r=$db->prepare("SELECT custom_name,custom_subtitle FROM admin_panel_config WHERE panel_key='LSPD' LIMIT 1");$r->execute();$row=$r->fetch();if(!empty($row['custom_name']))$__lspdName=$row['custom_name'];if(!empty($row['custom_subtitle']))$__lspdSub=$row['custom_subtitle']; } catch(Exception $e){}

// Thème global LSPD (défini par le staff dans general.php). Requête isolée :
// la colonne lspd_theme peut ne pas exister tant que general.php n'a pas tourné.
$__lspdTheme = 'dark';
try { $t=$db->prepare("SELECT lspd_theme FROM admin_panel_config WHERE panel_key='LSPD' LIMIT 1");$t->execute();$tv=$t->fetchColumn();if($tv && in_array($tv,['dark','light','gradient'],true))$__lspdTheme=$tv; } catch(Exception $e){}

// User grade
$__uid = (int)($_SESSION['user_id'] ?? 0);
// Badge : absences de l'agent traitées (validées/refusées) et pas encore vues
$__absNotifCount = 0;
try {
    $__an=$db->prepare("SELECT COUNT(*) FROM lspd_absences WHERE user_id=? AND notif_seen=0 AND statut IN ('validee','refusee')");
    $__an->execute([$__uid]); $__absNotifCount=(int)$__an->fetchColumn();
} catch(Exception $e) {}
$__grade = ''; $__gradeLvl = 0;
try { $s=$db->prepare("SELECT faction_rank FROM users WHERE id=?");$s->execute([$__uid]);$row=$s->fetch();$__grade=$row['faction_rank']??''; } catch(Exception $e){}

// Grades LSPD depuis la DB (roles.php) — même logique que effectifs.php
$__lspdRanks = [];
try {
    $__rStmt = $db->prepare(
        "SELECT fg.name FROM faction_grades fg
         JOIN factions f ON f.id = fg.faction_id
         WHERE f.faction_key COLLATE utf8mb4_unicode_ci = 'LSPD'
         ORDER BY fg.rank_order ASC"
    );
    $__rStmt->execute();
    $__lspdRanks = array_column($__rStmt->fetchAll(), 'name');
} catch(Exception $e) {}

// rank_order=1 = plus haut grade → niveau le plus élevé
$LSPD_RANKS_LEVEL = [];
foreach ($__lspdRanks as $__i => $__rName) {
    $LSPD_RANKS_LEVEL[$__rName] = count($__lspdRanks) - $__i;
}
$__gradeLvl = $LSPD_RANKS_LEVEL[$__grade] ?? 0;
$__maxLvl   = count($__lspdRanks); // niveau du grade le plus haut

// Seuils d'accès basés sur le grade réel en DB (rank_order croissant = grade inférieur)
// $LSPD_RANKS_LEVEL : rank_order=1 (Chief) → niveau le plus élevé (= count total)
$__lvlSgtI       = $LSPD_RANKS_LEVEL['Sergeant I']        ?? 0; // Supervision générale
$__lvlOfficerIII = $LSPD_RANKS_LEVEL['Police Officer III'] ?? 0; // ppa-agent + rapport-rookie

// Sergeant I minimum → toutes les pages Supervision sauf exceptions
$__isSupervisor  = hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])
                   || ($__lvlSgtI > 0 && $__gradeLvl >= $__lvlSgtI);

// Police Officer III minimum → ppa-agent et rapport-rookie uniquement
$__isOfficerIII  = hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])
                   || ($__lvlOfficerIII > 0 && $__gradeLvl >= $__lvlOfficerIII);

// Lieutenant I minimum → Logs d'activité
$__isLt          = hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])
                   || ($__maxLvl > 0 && $__gradeLvl >= ceil($__maxLvl * 0.55));

// Captain minimum → fonctions Command
$__isCommand     = hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])
                   || ($__maxLvl > 0 && $__gradeLvl >= ceil($__maxLvl * 0.75));

// ── Capacités d'action (faction_acl) pour les pages MDT spécifiques ──────────
if (!function_exists('faction_acl_can')) {
    $__faclP = dirname(__DIR__, 3) . '/includes/faction_acl.php';
    if (file_exists($__faclP)) require_once $__faclP;
}
$__canTimer = function_exists('faction_acl_can') ? faction_acl_can($db, $__uid, 'LSPD', 'temps-service.manage') : $__isSupervisor;
$__canCdn   = function_exists('faction_acl_can') ? faction_acl_can($db, $__uid, 'LSPD', 'cdn.view') : true;
$__canDispatchLogs = function_exists('faction_acl_can') ? faction_acl_can($db, $__uid, 'LSPD', 'dispatch-logs.view') : $__isSupervisor;

$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// ── Active pages & grade permissions depuis admin_panel_config ────────────
$__lspdActivePages = [];
try {
    $__s = $db->prepare("SELECT active_pages FROM admin_panel_config WHERE panel_key='LSPD' LIMIT 1");
    $__s->execute(); $__r=$__s->fetch();
    if($__r&&!empty($__r['active_pages'])) $__lspdActivePages=json_decode($__r['active_pages'],true)?:[];
} catch(Exception $e){}

// Rediriger si la page courante est désactivée
if(isset($__lspdActivePages[$currentPage]) && $__lspdActivePages[$currentPage]===false) {
    redirect(SITE_URL.'/pages/dashboard.php');
}
// Vérifier permission de grade sur la page courante
$__isStaffBypass = hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']);
if(!$__isStaffBypass) {
    try {
        $__sp=$db->prepare("SELECT allowed_grades FROM panel_page_permissions WHERE panel_key='LSPD' AND page_key=? LIMIT 1");
        $__sp->execute([$currentPage]); $__pr=$__sp->fetch();
        if($__pr){$__al=json_decode($__pr['allowed_grades'],true)?:['everyone'];
            if(!in_array('everyone',$__al)&&!in_array($__grade,$__al)) redirect(SITE_URL.'/pages/dashboard.php');}
    } catch(Exception $e){}
}

// ── Protection d'accès direct : Supervision ──────────────────────────────
// Bloque l'accès par URL directe, indépendamment de l'affichage sidebar
$__pagesSupervision = ['effectifs','equipement','sanctions','divisions','ia','statistiques','vehicule_lspd'];
$__pagesOfficerIII  = ['ppa-agent','rapport-rookie'];
if (in_array($currentPage, $__pagesSupervision) && !$__isSupervisor) {
    redirect(SITE_URL . '/pages/lspd/dashboard.php');
}
if (in_array($currentPage, $__pagesOfficerIII) && !$__isOfficerIII) {
    redirect(SITE_URL . '/pages/lspd/dashboard.php');
}
// Pages MDT à permission d'action dédiée
if ($currentPage === 'temps_service' && !$__canTimer) {
    redirect(SITE_URL . '/pages/lspd/dashboard.php');
}
if ($currentPage === 'archive' && !$__canCdn) {
    redirect(SITE_URL . '/pages/lspd/dashboard.php');
}
if ($currentPage === 'logs_dispatch' && !$__canDispatchLogs) {
    redirect(SITE_URL . '/pages/lspd/dashboard.php');
}

// Navigation MDT structurée en 4 catégories (+ Dashboard épinglé en tête).
$__navPages = [
    ['file'=>'dashboard',      'label'=>'Dashboard',          'icon'=>'fas fa-grip',              'section'=>''],

    // ── Opérations ─────────────────────────────────────────────────────────
    ['file'=>'profils',        'label'=>'Profils Civils',     'icon'=>'fas fa-users',             'section'=>'Opérations'],
    ['file'=>'ppa',            'label'=>'Demandes PPA',       'icon'=>'fas fa-gun',               'section'=>'Opérations'],
    ['file'=>'armes-civils',   'label'=>'Recensement Armes',  'icon'=>'fas fa-boxes-stacked',     'section'=>'Opérations'],
    ['file'=>'vehicles',       'label'=>'Véhicules',          'icon'=>'fas fa-car',               'section'=>'Opérations'],
    ['file'=>'casiers',        'label'=>'Casiers Judiciaires','icon'=>'fas fa-gavel',             'section'=>'Opérations'],
    ['file'=>'recherches',     'label'=>'Avis de Recherche',  'icon'=>'fas fa-crosshairs',        'section'=>'Opérations'],
    ['file'=>'reports',        'label'=>'Rapports',           'icon'=>'fas fa-file-alt',          'section'=>'Opérations'],
    ['file'=>'offenses',       'label'=>'Code Pénal',         'icon'=>'fas fa-book-open',         'section'=>'Opérations'],
    ['file'=>'dispatch',       'label'=>'Dispatch',           'icon'=>'fas fa-headset',           'section'=>'Opérations'],
    ['file'=>'map',            'label'=>'Plans tactiques',    'icon'=>'fas fa-map-location-dot',  'section'=>'Opérations'],
    ['file'=>'saisies',        'label'=>'Saisies',            'icon'=>'fas fa-box-archive',       'section'=>'Opérations'],
    ['file'=>'plainte',        'label'=>'Plaintes',           'icon'=>'fas fa-file-circle-exclamation','section'=>'Opérations'],
    ['file'=>'convocation',    'label'=>'Convocations',       'icon'=>'fas fa-envelope-open-text','section'=>'Opérations'],
    ['file'=>'deposition',     'label'=>'Dépositions',        'icon'=>'fas fa-scroll',            'section'=>'Opérations'],
    ['file'=>'bracelets-electronique','label'=>'Bracelets Élec.','icon'=>'fas fa-circle-dot',     'section'=>'Opérations'],

    // ── Gestion LSPD ───────────────────────────────────────────────────────
    ['file'=>'effectifs',      'label'=>'Effectifs',          'icon'=>'fas fa-id-card',           'section'=>'Gestion LSPD'],
    ['file'=>'absence',        'label'=>'Absences',           'icon'=>'fas fa-calendar-xmark',    'section'=>'Gestion LSPD'],
    ['file'=>'temps_service',  'label'=>'Temps de Service',   'icon'=>'fas fa-business-time',      'section'=>'Gestion LSPD'],
    ['file'=>'ppa-agent',      'label'=>'PPA Agent',          'icon'=>'fas fa-gun',               'section'=>'Gestion LSPD'],
    ['file'=>'rapport-rookie', 'label'=>'Rapports Rookie',    'icon'=>'fas fa-user-graduate',     'section'=>'Gestion LSPD'],
    ['file'=>'vehicule_lspd',  'label'=>'Flotte Véhicules',   'icon'=>'fas fa-car-side',          'section'=>'Gestion LSPD'],
    ['file'=>'equipement',     'label'=>'Équipement',         'icon'=>'fas fa-gun',               'section'=>'Gestion LSPD'],
    ['file'=>'sanctions',      'label'=>'Sanctions',          'icon'=>'fas fa-clipboard-list',    'section'=>'Gestion LSPD'],
    ['file'=>'divisions',      'label'=>'Grades & Divisions', 'icon'=>'fas fa-address-book',      'section'=>'Gestion LSPD'],

    // ── Administration ─────────────────────────────────────────────────────
    ['file'=>'archive',        'label'=>'Archives CDN',       'icon'=>'fas fa-images',            'section'=>'Administration'],
    ['file'=>'ia',             'label'=>'Affaires Internes',  'icon'=>'fas fa-eye',               'section'=>'Administration'],
    ['file'=>'statistiques',   'label'=>'Statistiques',       'icon'=>'fas fa-chart-line',        'section'=>'Administration'],

    // ── Logs & Services ────────────────────────────────────────────────────
    ['file'=>'service',        'label'=>'Prises de Service',  'icon'=>'fas fa-clock',             'section'=>'Logs & Services'],
    ['file'=>'logs',           'label'=>"Logs d'activité",    'icon'=>'fas fa-clipboard-list',    'section'=>'Logs & Services'],
    ['file'=>'logs_dispatch',  'label'=>'Logs Dispatch',      'icon'=>'fas fa-clock-rotate-left', 'section'=>'Logs & Services'],
];
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?php echo htmlspecialchars($__lspdTheme); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle??'LSPD'); ?> — <?php echo htmlspecialchars($__lspdName); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=IBM+Plex+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--blue:#2563eb;--blue-l:#60a5fa;--blue-xl:#bfdbfe;--bg:#03080f;--bg-c:#060d1a;--bg-e:#0a1020;--bg-ov:#0e1830;--bdr:rgba(37,99,235,.14);--bdr-h:rgba(37,99,235,.35);--txt:#e8edf5;--txt-s:#94a3b8;--txt-m:#374151;--green:#10b981;--red:#ef4444;--yellow:#f59e0b;--purple:#8b5cf6}
body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:var(--bg);color:var(--txt);overflow-x:hidden;font-size:14px}
/* ═══════════════ SIDEBAR (v2 — refined) ═══════════════
   Amélioration UX : indicateur d'actif animé, accent par section,
   zone de clic élargie, transitions fluides, meilleur contraste.
=============================================================*/
.ls-sb{position:fixed;left:0;top:0;width:262px;height:100vh;background:linear-gradient(180deg,#050c1e 0%,#030810 55%,var(--bg) 100%);border-right:1px solid var(--bdr);overflow-y:auto;z-index:1000;display:flex;flex-direction:column;box-shadow:inset -1px 0 0 rgba(37,99,235,.05),4px 0 30px rgba(0,0,0,.35)}
.ls-sb::-webkit-scrollbar{width:4px}
.ls-sb::-webkit-scrollbar-track{background:transparent}
.ls-sb::-webkit-scrollbar-thumb{background:linear-gradient(180deg,rgba(37,99,235,.35),rgba(37,99,235,.15));border-radius:2px}
.ls-sb::-webkit-scrollbar-thumb:hover{background:rgba(37,99,235,.55)}
/* halo décoratif en haut de la sidebar */
.ls-sb::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(37,99,235,.6),transparent);pointer-events:none}
/* Brand */
.ls-brand{padding:1.35rem 1.2rem 1.05rem;border-bottom:1px solid var(--bdr);display:flex;align-items:center;gap:.8rem;position:relative}
.ls-brand::after{content:'';position:absolute;bottom:-1px;left:1.2rem;right:1.2rem;height:1px;background:linear-gradient(90deg,transparent,rgba(37,99,235,.35),transparent)}
.ls-brand-ico{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.1rem;flex-shrink:0;box-shadow:0 4px 18px rgba(37,99,235,.45),inset 0 1px 0 rgba(255,255,255,.15);position:relative;overflow:hidden}
.ls-brand-ico::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,transparent 40%,rgba(255,255,255,.12) 50%,transparent 60%);pointer-events:none}
.ls-brand-name{font-family:'Syne',serif;font-size:.95rem;font-weight:800;color:#fff;letter-spacing:.02em}
.ls-brand-sub{font-size:.6rem;color:var(--txt-m);letter-spacing:.11em;text-transform:uppercase;margin-top:.15rem}
/* User card */
.ls-user{margin:.75rem 1rem;padding:.65rem .85rem;background:linear-gradient(135deg,rgba(37,99,235,.09),rgba(37,99,235,.04));border:1px solid rgba(37,99,235,.22);border-radius:10px;display:flex;align-items:center;gap:.6rem;transition:border-color .2s,background .2s}
.ls-user:hover{border-color:rgba(37,99,235,.38);background:linear-gradient(135deg,rgba(37,99,235,.14),rgba(37,99,235,.06))}
.ls-user i{color:var(--blue-l);font-size:.7rem;filter:drop-shadow(0 0 4px currentColor)}
.ls-user .name{font-size:.78rem;font-weight:600;color:#e8edf5;line-height:1.2}
.ls-user .grade{font-size:.64rem;color:var(--blue-l);margin-top:.15rem;font-weight:600;letter-spacing:.02em}
/* Nav */
.ls-nav{padding:.55rem 0 2rem;flex:1;display:flex;flex-direction:column}
.ls-sect{padding:.85rem 1.2rem .3rem;font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.16em;color:var(--txt-m);display:flex;align-items:center;gap:.5rem;position:relative}
.ls-sect::before{content:'';width:3px;height:3px;border-radius:50%;background:rgba(37,99,235,.4);box-shadow:0 0 6px rgba(37,99,235,.6);flex-shrink:0}
.ls-sect::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,rgba(37,99,235,.18),transparent);margin-left:.3rem}
/* Links — zone de clic élargie, animation latérale */
.ls-link{display:flex;align-items:center;gap:.7rem;padding:.62rem 1.2rem .62rem 1.15rem;margin:.08rem .55rem;border-radius:9px;color:var(--txt-s);text-decoration:none;font-size:.81rem;font-weight:500;transition:color .18s,background .18s,transform .18s,padding-left .18s;position:relative;overflow:hidden}
.ls-link::before{content:'';position:absolute;left:-3px;top:50%;transform:translateY(-50%);width:3px;height:0;background:linear-gradient(180deg,var(--blue-l),var(--blue));border-radius:0 3px 3px 0;transition:height .22s cubic-bezier(.34,1.56,.64,1);box-shadow:0 0 10px rgba(37,99,235,.45)}
.ls-link i{width:16px;text-align:center;font-size:.82rem;transition:transform .18s,color .18s;flex-shrink:0}
.ls-link span{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ls-link:hover{color:var(--blue-xl);background:rgba(37,99,235,.07);padding-left:1.25rem}
.ls-link:hover i{color:var(--blue-l);transform:scale(1.08)}
.ls-link:hover::before{height:40%}
.ls-link.active{color:#fff;background:linear-gradient(90deg,rgba(37,99,235,.18),rgba(37,99,235,.05));font-weight:700;padding-left:1.25rem}
.ls-link.active i{color:var(--blue-l);filter:drop-shadow(0 0 5px rgba(96,165,250,.6))}
.ls-link.active::before{height:62%}
.ls-link.active::after{content:'';position:absolute;right:.9rem;top:50%;transform:translateY(-50%);width:5px;height:5px;border-radius:50%;background:var(--blue-l);box-shadow:0 0 8px var(--blue-l)}
.ls-nav-badge{flex-shrink:0;min-width:18px;height:18px;padding:0 .35rem;border-radius:9px;background:var(--red);color:#fff;font-size:.62rem;font-weight:800;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 0 8px rgba(239,68,68,.5)}
.ls-divider{height:1px;background:linear-gradient(90deg,transparent,var(--bdr),transparent);margin:.7rem 1.2rem}
/* MAIN */
.ls-main{margin-left:262px;min-height:100vh}
.ls-topbar{position:sticky;top:0;z-index:100;background:rgba(3,8,15,.96);backdrop-filter:blur(12px);border-bottom:1px solid var(--bdr);padding:.85rem 1.65rem;display:flex;justify-content:space-between;align-items:center}
.ls-topbar-title{font-family:'Syne',serif;font-size:1.1rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:.65rem}
.ls-topbar-title i{color:var(--blue-l);font-size:.9rem}
.ls-topbar-sub{font-size:.69rem;color:var(--txt-s);margin-top:.1rem}
.ls-topbar-right{display:flex;align-items:center;gap:.6rem}
.ls-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .85rem;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.22);border-radius:20px;font-size:.77rem;font-weight:600;color:var(--blue-xl)}
.ls-content{padding:1.4rem 1.65rem}
/* REUSABLE COMPONENTS */
.card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.35rem;margin-bottom:1.35rem;transition:border-color .2s}.card:hover{border-color:var(--bdr-h)}
.card-title{font-size:.71rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}.card-title i{color:var(--blue-l)}
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:1rem;margin-bottom:1.4rem}
.kpi{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.1rem;position:relative;overflow:hidden}
.kpi::after{content:'';position:absolute;top:-15px;right:-15px;width:60px;height:60px;background:radial-gradient(circle,rgba(37,99,235,.1) 0%,transparent 70%);pointer-events:none}
.kpi-ico{width:30px;height:30px;border-radius:8px;background:rgba(37,99,235,.12);color:var(--blue-l);display:flex;align-items:center;justify-content:center;font-size:.8rem;margin-bottom:.65rem}
.kpi-lbl{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.22rem}
.kpi-val{font-size:1.5rem;font-weight:800;font-family:'IBM Plex Mono',monospace;color:#f9fafb;line-height:1}
.kpi-val.blue{color:var(--blue-l)}.kpi-val.green{color:#6ee7b7}.kpi-val.red{color:#fca5a5}.kpi-val.yellow{color:#fde68a}.kpi-val.purple{color:#c4b5fd}
.kpi-sub{font-size:.69rem;color:var(--txt-m);margin-top:.28rem}
.tbl-wrap{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;overflow:hidden;margin-bottom:1.35rem}
.tbl{width:100%;border-collapse:collapse}.tbl th{text-align:left;padding:.72rem 1rem;background:rgba(37,99,235,.06);border-bottom:2px solid rgba(37,99,235,.18);font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s)}
.tbl td{padding:.7rem 1rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.81rem}.tbl tr:hover td{background:rgba(37,99,235,.03)}.tbl tr:last-child td{border-bottom:none}
.btn{display:inline-flex;align-items:center;gap:.42rem;padding:.58rem 1.1rem;border-radius:8px;font-weight:600;font-size:.81rem;cursor:pointer;transition:all .17s;text-decoration:none;border:none;font-family:inherit}
.btn-primary{background:linear-gradient(135deg,var(--blue),#1d4ed8);color:#fff;box-shadow:0 3px 12px rgba(37,99,235,.3)}.btn-primary:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(37,99,235,.4)}
.btn-secondary{background:rgba(37,99,235,.1);color:var(--blue-xl);border:1px solid rgba(37,99,235,.25)}.btn-secondary:hover{background:rgba(37,99,235,.18)}
.btn-ghost{background:transparent;color:var(--txt-s);border:1px solid var(--bdr)}.btn-ghost:hover{background:var(--bg-ov);color:var(--txt)}
.btn-success{background:rgba(16,185,129,.1);color:#6ee7b7;border:1px solid rgba(16,185,129,.25)}.btn-danger{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.25)}
.btn-warning{background:rgba(245,158,11,.1);color:#fde68a;border:1px solid rgba(245,158,11,.25)}.btn-sm{padding:.33rem .72rem;font-size:.75rem}.btn-xs{padding:.2rem .52rem;font-size:.69rem}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1.05rem}.form-full{grid-column:1/-1}.form-group{display:flex;flex-direction:column;gap:.3rem}
.form-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m)}
.form-input,.form-select,.form-textarea{width:100%;padding:.62rem .88rem;background:#020508;border:1px solid rgba(37,99,235,.2);border-radius:7px;color:var(--txt);font-family:'IBM Plex Sans',inherit;font-size:.83rem;transition:all .17s}
.form-input:focus,.form-select:focus,.form-textarea:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.12)}.form-input::placeholder{color:var(--txt-m)}.form-select option{background:#0a1020}.form-textarea{resize:vertical;min-height:85px}
.modal-ov{position:fixed;inset:0;background:rgba(0,0,0,.88);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;z-index:9999;padding:1.5rem}.modal-ov:not(.hidden){display:flex}
.modal{background:linear-gradient(140deg,var(--bg-e),var(--bg));border:1px solid rgba(37,99,235,.28);border-radius:15px;box-shadow:0 0 80px rgba(0,0,0,.7);width:90%;max-width:640px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden}
.modal-sm{max-width:430px}.modal-lg{max-width:850px}.modal-xl{max-width:1100px}
.modal-hd{padding:1.15rem 1.45rem;border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;background:rgba(37,99,235,.04)}
.modal-hd h2{font-family:'Syne',serif;font-size:.95rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:.62rem}.modal-hd h2 i{color:var(--blue-l)}
.modal-cl{width:28px;height:28px;border-radius:7px;background:rgba(37,99,235,.08);border:1px solid var(--bdr);color:var(--txt-s);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1rem;transition:all .17s}
.modal-cl:hover{background:rgba(239,68,68,.15);color:#fca5a5;transform:rotate(90deg)}
.modal-bd{padding:1.45rem;overflow-y:auto;flex:1}.modal-ft{padding:1rem 1.45rem;border-top:1px solid var(--bdr);background:rgba(0,0,0,.2);display:flex;justify-content:flex-end;gap:.6rem}
.alert{padding:.78rem 1.05rem;border-radius:9px;margin-bottom:1.2rem;display:flex;align-items:center;gap:.6rem;font-size:.81rem;font-weight:500}
.alert-success{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.22);color:#6ee7b7}.alert-error{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.22);color:#fca5a5}
.alert-info{background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.22);color:var(--blue-xl)}.alert-warning{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.22);color:#fde68a}
.badge{display:inline-flex;align-items:center;gap:.27rem;padding:.21rem .58rem;border-radius:5px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.bg-blue{background:rgba(37,99,235,.1);color:var(--blue-xl);border:1px solid rgba(37,99,235,.2)}.bg-green{background:rgba(16,185,129,.1);color:#6ee7b7;border:1px solid rgba(16,185,129,.2)}
.bg-red{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.2)}.bg-yellow{background:rgba(245,158,11,.1);color:#fde68a;border:1px solid rgba(245,158,11,.2)}
.bg-purple{background:rgba(139,92,246,.1);color:#c4b5fd;border:1px solid rgba(139,92,246,.2)}.bg-gray{background:rgba(107,114,128,.1);color:#9ca3af;border:1px solid rgba(107,114,128,.2)}
.bg-orange{background:rgba(249,115,22,.1);color:#fdba74;border:1px solid rgba(249,115,22,.2)}
.mono{font-family:'IBM Plex Mono',monospace}.hidden{display:none !important}
.grid2{display:grid;grid-template-columns:repeat(2,1fr);gap:1.35rem}.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:1.05rem}
/* SEVERITY BADGES */
.sev-1{color:#6ee7b7;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2)}
.sev-2{color:#93c5fd;background:rgba(37,99,235,.1);border:1px solid rgba(37,99,235,.2)}
.sev-3{color:#fde68a;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2)}
.sev-4{color:#fdba74;background:rgba(249,115,22,.1);border:1px solid rgba(249,115,22,.2)}
.sev-5{color:#fca5a5;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2)}
/* WANTED */
.wanted-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.1rem;border-left:4px solid var(--red)}
.wanted-card.low{border-left-color:var(--yellow)}.wanted-card.medium{border-left-color:var(--blue)}.wanted-card.high{border-left-color:var(--yellow)}.wanted-card.critical{border-left-color:var(--red)}
@media(max-width:1100px){.ls-sb{transform:translateX(-100%)}.ls-main{margin-left:0}.form-grid{grid-template-columns:1fr}.grid2{grid-template-columns:1fr}}
/* ═══════════ USER CARD (avatar + identité RP + grade) ═══════════ */
.ls-user{position:relative}
.ls-user-ava{width:38px;height:38px;border-radius:10px;object-fit:cover;flex-shrink:0;border:1px solid rgba(37,99,235,.3);background:#0a1020}
.ls-user-ava-ph{display:grid;place-items:center;font-weight:800;color:#fff;font-size:.95rem;background:linear-gradient(135deg,#3b82f6,#1d4ed8)}
.ls-user-meta{flex:1;min-width:0}
.ls-user-meta .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ls-user-meta .grade{display:flex;align-items:center;gap:.3rem}
.ls-user-meta .grade i{font-size:.6rem}
.ls-user-dot{position:absolute;top:.55rem;right:.6rem;width:8px;height:8px;border-radius:50%;box-shadow:0 0 6px currentColor}
/* ═══════════ SCROLLBAR CUSTOM GLOBALE (fin + glow cyan) ═══════════ */
*{scrollbar-width:thin;scrollbar-color:rgba(37,99,235,.45) transparent}
*::-webkit-scrollbar{width:9px;height:9px}
*::-webkit-scrollbar-track{background:transparent}
*::-webkit-scrollbar-thumb{background:linear-gradient(180deg,rgba(0,229,255,.32),rgba(37,99,235,.22));border-radius:6px;border:2px solid transparent;background-clip:padding-box}
*::-webkit-scrollbar-thumb:hover{background:rgba(0,229,255,.55);border:2px solid transparent;background-clip:padding-box}
/* ═══════════ THÈMES GLOBAUX (définis par le staff dans general.php) ═══════════ */
html[data-theme="light"]{--bg:#e9eef6;--bg-c:#ffffff;--bg-e:#f7f9fc;--bg-ov:#dbe3f0;--bdr:rgba(37,99,235,.16);--bdr-h:rgba(37,99,235,.4);--txt:#0f1b2d;--txt-s:#475569;--txt-m:#8a97a8;--blue-xl:#1e40af}
html[data-theme="light"] body{background:#e9eef6;color:#0f1b2d}
html[data-theme="light"] .ls-sb{background:linear-gradient(180deg,#f4f7fb,#eaf0f8 60%,#e9eef6);border-right-color:rgba(37,99,235,.16);box-shadow:4px 0 24px rgba(15,27,45,.06)}
html[data-theme="light"] .ls-brand-name{color:#0f1b2d}
html[data-theme="light"] .ls-topbar{background:rgba(255,255,255,.92)}
html[data-theme="light"] .ls-topbar-title{color:#0f1b2d}
html[data-theme="light"] .form-input,html[data-theme="light"] .form-select,html[data-theme="light"] .form-textarea{background:#ffffff;color:#0f1b2d}
html[data-theme="light"] .form-select option{background:#ffffff;color:#0f1b2d}
html[data-theme="light"] .kpi-val{color:#0f1b2d}
html[data-theme="light"] .tbl td{color:#1e293b}
html[data-theme="light"] .modal{background:linear-gradient(140deg,#ffffff,#f3f6fb)}
html[data-theme="light"] .modal-hd h2{color:#0f1b2d}
html[data-theme="gradient"]{--bg:#04101f;--bg-c:#0a1c33;--bg-e:#0b2138;--blue-l:#22d3ee;--blue:#0891b2}
html[data-theme="gradient"] body{background:linear-gradient(135deg,#04101f,#062033,#03283a,#04101f);background-size:400% 400%;animation:mdtGrad 18s ease infinite}
@keyframes mdtGrad{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
html[data-theme="gradient"] .ls-sb{background:linear-gradient(180deg,#06243a,#041726 60%,#04101f)}
html[data-theme="gradient"] .ls-brand-ico{background:linear-gradient(135deg,#06b6d4,#0e7490)}
html[data-theme="gradient"] .ls-link.active{background:linear-gradient(90deg,rgba(8,145,178,.22),rgba(34,211,238,.06))}
</style>
</head>
<body>
<aside class="ls-sb">
<div class="ls-brand"><div class="ls-brand-ico"><i class="fas fa-shield-halved"></i></div><div><div class="ls-brand-name"><?php echo htmlspecialchars($__lspdName);?></div><div class="ls-brand-sub"><?php echo htmlspecialchars($__lspdSub);?></div></div></div>
<div class="ls-user">
<?php $__ava=$_SESSION['user_avatar']??''; if(!empty($__ava)):?>
<img class="ls-user-ava" src="<?php echo htmlspecialchars($__ava);?>" alt="">
<?php else:?>
<span class="ls-user-ava ls-user-ava-ph"><?php echo htmlspecialchars(mdt_session_display_initial());?></span>
<?php endif;?>
<div class="ls-user-meta"><div class="name"><?php echo htmlspecialchars(mdt_session_display_name());?></div><?php if($__grade):?><div class="grade"><i class="fas fa-shield-halved"></i> <?php echo htmlspecialchars($__grade);?></div><?php endif;?></div>
<span class="ls-user-dot" style="background:<?php echo $__grade?'#4ade80':'#374151';?>" title="En ligne"></span>
</div>
<nav class="ls-nav">
<?php $lastSec=''; foreach($__navPages as $p):
    // Filtre: page désactivée dans mdt.php ?
    $__pFile=$p['file'];
    if(isset($__lspdActivePages[$__pFile])&&$__lspdActivePages[$__pFile]===false) continue;
    // Filtre: permission de grade ?
    if(!$__isStaffBypass){
        try{$__xp=$db->prepare("SELECT allowed_grades FROM panel_page_permissions WHERE panel_key='LSPD' AND page_key=? LIMIT 1");
            $__xp->execute([$__pFile]);$__xr=$__xp->fetch();
            if($__xr){$__xa=json_decode($__xr['allowed_grades'],true)?:['everyone'];
                if(!in_array('everyone',$__xa)&&!in_array($__grade,$__xa)) continue;}
        }catch(Exception $e){}
    }
    $sec=$p['section']??'';
    // Masquer les pages dont le fichier n'existe pas encore (évite les liens cassés)
    if(!file_exists(__DIR__.'/../'.$p['file'].'.php')) continue;
    // ── Filtres d'accès Supervision (avant le header de section pour éviter un titre orphelin) ──
    if (in_array($p['file'], ['ppa-agent','rapport-rookie']) && !$__isOfficerIII) continue;
    if (in_array($p['file'], ['effectifs','equipement','sanctions','divisions','ia','statistiques','vehicule_lspd']) && !$__isSupervisor) continue;
    if($p['file']==='logs'&&!$__isLt) continue; // Lieutenant I minimum pour les logs
    if($p['file']==='temps_service'&&!$__canTimer) continue; // Gestion Timer requise
    if($p['file']==='archive'&&!$__canCdn) continue; // Permission CDN requise
    if($p['file']==='logs_dispatch'&&!$__canDispatchLogs) continue; // Permission LOGS_DISPATCH requise
    // Afficher l'en-tête de section uniquement si nécessaire
    if(!empty($sec)&&$sec!==$lastSec){$lastSec=$sec;echo '<div class="ls-sect">'.htmlspecialchars($sec).'</div>';}
    $isAct=($currentPage===$p['file'])?' active':'';
?>
<a href="<?php echo SITE_URL;?>/pages/lspd/<?php echo $p['file'];?>.php" class="ls-link<?php echo $isAct;?>"><i class="<?php echo $p['icon'];?>"></i><span><?php echo htmlspecialchars($p['label']);?></span><?php if($p['file']==='absence' && $__absNotifCount>0):?><span class="ls-nav-badge"><?php echo $__absNotifCount;?></span><?php endif;?></a>
<?php endforeach;?>
<div class="ls-divider"></div>
<?php if(hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])):?>
<a href="<?php echo SITE_URL;?>/pages/staff/dashboard.php" class="ls-link"><i class="fas fa-layer-group"></i><span>Panel Staff</span></a>
<?php endif;?>
<a href="<?php echo SITE_URL;?>/pages/dashboard.php" class="ls-link"><i class="fas fa-house"></i><span>Accueil MDT</span></a>
<a href="<?php echo SITE_URL;?>/logout.php" class="ls-link"><i class="fas fa-right-from-bracket"></i><span>Déconnexion</span></a>
</nav>
</aside>
<main class="ls-main">
<div class="ls-topbar">
<div><div class="ls-topbar-title"><i class="fas fa-shield-halved"></i><?php echo htmlspecialchars($pageTitle??'Panel LSPD');?></div><div class="ls-topbar-sub">Los Santos Police Department — MDT</div></div>
<div class="ls-topbar-right">
<?php if($__grade):?><span class="badge bg-blue" style="font-size:.72rem"><?php echo htmlspecialchars($__grade);?></span><?php endif;?>
<span class="ls-badge"><i class="fas fa-shield-halved"></i><?php echo htmlspecialchars($__lspdName);?></span>
</div>
</div>
<div class="ls-content">

<!-- ══ CONFIRM MODAL GLOBAL ══ -->
<div id="lspd-confirm-ov" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);backdrop-filter:blur(8px);z-index:99999;align-items:center;justify-content:center;padding:1.5rem">
<div style="background:linear-gradient(140deg,var(--bg-e),var(--bg));border:1px solid rgba(239,68,68,.3);border-radius:15px;max-width:400px;width:90%;overflow:hidden;box-shadow:0 0 80px rgba(0,0,0,.7)">
    <div style="padding:2rem 1.5rem 1rem;text-align:center">
        <div id="lspd-confirm-ico" style="width:64px;height:64px;border-radius:50%;margin:0 auto .85rem;display:flex;align-items:center;justify-content:center;font-size:1.5rem;background:rgba(239,68,68,.12);border:2px solid rgba(239,68,68,.3);color:#fca5a5"><i class="fas fa-trash"></i></div>
        <div id="lspd-confirm-title" style="font-family:'Syne',serif;font-size:1.05rem;font-weight:800;color:#fff;margin-bottom:.5rem">Confirmer la suppression</div>
        <div id="lspd-confirm-msg" style="font-size:.83rem;color:var(--txt-s);line-height:1.55">Cette action est <strong>irréversible</strong>.</div>
        <div id="lspd-confirm-target" style="display:none;margin-top:.65rem;padding:.4rem .8rem;background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:7px;font-family:'IBM Plex Mono',monospace;font-size:.75rem;color:#fca5a5"></div>
    </div>
    <div style="padding:.75rem 1.5rem 1.5rem;display:flex;justify-content:center;gap:.65rem">
        <button class="btn btn-ghost" onclick="LSPD.confirmCancel()">Annuler</button>
        <button id="lspd-confirm-ok-btn" class="btn btn-danger" onclick="LSPD.confirmOk()"><i class="fas fa-check"></i> <span id="lspd-confirm-ok-label">Supprimer</span></button>
    </div>
</div>
</div>
<!-- ══ TOAST CONTAINER ══ -->
<div id="lspd-toasts" style="position:fixed;top:70px;right:20px;z-index:99998;display:flex;flex-direction:column;gap:.5rem;pointer-events:none"></div>