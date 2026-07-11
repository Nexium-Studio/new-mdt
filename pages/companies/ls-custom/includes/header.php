<?php
// ═══════════════════════════════════════════════
// pages/companies/ls-custom/includes/header.php
// ═══════════════════════════════════════════════
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';
checkPageActive('LS_CUSTOM');

$__lscAllPages = [
    ['file'=>'dashboard',      'label'=>'Dashboard',         'icon'=>'fas fa-chart-line',          'section'=>''],
    ['file'=>'effectifs',      'label'=>'Effectifs',         'icon'=>'fas fa-users',               'section'=>'Ressources Humaines'],
    ['file'=>'prises-service', 'label'=>'Prises de Service', 'icon'=>'fas fa-clock',               'section'=>''],
    ['file'=>'sanctions',      'label'=>'Sanctions',         'icon'=>'fas fa-gavel',               'section'=>''],
    ['file'=>'stock',          'label'=>'Stock',             'icon'=>'fas fa-boxes',               'section'=>'Gestion'],
    ['file'=>'vente',          'label'=>'Vente',             'icon'=>'fas fa-shopping-cart',       'section'=>''],
    ['file'=>'facturation',    'label'=>'Facturation',       'icon'=>'fas fa-file-invoice-dollar', 'section'=>''],
    ['file'=>'depenses',       'label'=>'Dépenses',          'icon'=>'fas fa-money-bill-wave',     'section'=>''],
];
$__lscNavPages = getActiveNavItems('LS_CUSTOM', $__lscAllPages);
$currentPage   = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle ?? 'Panel'); ?> — LS Custom</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;800;900&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════════════════════
   LS CUSTOM — Design System · Cyberpunk Violet
   ════════════════════════════════════════════════════════════ */
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --violet:     #8b5cf6;
    --violet-d:   #5b21b6;
    --violet-l:   #a78bfa;
    --violet-xl:  #ddd6fe;
    --cyan:       #06b6d4;
    --cyan-l:     #67e8f9;
    --bg:         #07060d;
    --bg-b:       #0d0b16;
    --bg-c:       #120f1e;
    --bg-e:       #18152a;
    --bdr:        rgba(139,92,246,.13);
    --bdr-h:      rgba(139,92,246,.36);
    --txt:        #ede8ff;
    --txt-s:      #7c6fa0;
    --txt-m:      #3a3154;
    --green:      #22d3ee;
    --red:        #f43f5e;
    --yellow:     #fbbf24;
    --sidebar-w:  268px;
}

html { scroll-behavior: smooth; }
body {
    font-family: 'DM Sans', system-ui, sans-serif;
    background: var(--bg);
    color: var(--txt);
    overflow-x: hidden;
    font-size: 14.5px;
}

/* Hex grid ambient texture */
body::before {
    content: '';
    position: fixed; inset: 0;
    background-image:
        radial-gradient(circle at 20% 50%, rgba(139,92,246,.07) 0%, transparent 50%),
        radial-gradient(circle at 80% 20%, rgba(6,182,212,.05) 0%, transparent 40%);
    pointer-events: none; z-index: 0;
}
body::after {
    content: '';
    position: fixed; inset: 0;
    background-image:
        repeating-linear-gradient(
            0deg,
            transparent,
            transparent 44px,
            rgba(139,92,246,.025) 44px,
            rgba(139,92,246,.025) 45px
        ),
        repeating-linear-gradient(
            90deg,
            transparent,
            transparent 44px,
            rgba(139,92,246,.025) 44px,
            rgba(139,92,246,.025) 45px
        );
    pointer-events: none; z-index: 0;
}

/* ══ SIDEBAR ═══════════════════════════════════════════════ */
.lsc-sidebar {
    position: fixed; left: 0; top: 0;
    width: var(--sidebar-w); height: 100vh;
    background: linear-gradient(165deg, #0f0c1e 0%, var(--bg) 100%);
    border-right: 1px solid var(--bdr);
    overflow-y: auto; z-index: 1000;
    display: flex; flex-direction: column;
    box-shadow: 4px 0 40px rgba(139,92,246,.08);
}
.lsc-sidebar::after {
    content: '';
    position: absolute; top: 0; right: 0;
    width: 1px; height: 100%;
    background: linear-gradient(180deg,
        transparent 0%, var(--violet) 30%, var(--cyan) 70%, transparent 100%);
    opacity: .18;
}

.lsc-brand {
    padding: 1.5rem 1.4rem 1.2rem;
    border-bottom: 1px solid var(--bdr);
    position: relative;
}
.lsc-brand::before {
    content: 'LS CUSTOM';
    position: absolute; top: .7rem; right: 1.2rem;
    font-family: 'Orbitron', monospace;
    font-size: .42rem; font-weight: 900;
    letter-spacing: .35em;
    color: var(--violet); opacity: .25;
}
.lsc-logo {
    width: 50px; height: 50px; border-radius: 11px;
    background: linear-gradient(135deg, var(--violet), var(--violet-d));
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; color: #fff; margin-bottom: .85rem;
    box-shadow: 0 0 28px rgba(139,92,246,.45),
                0 0 8px rgba(139,92,246,.3) inset;
    position: relative; overflow: hidden;
}
.lsc-logo::after {
    content: '';
    position: absolute; top: -50%; left: -50%;
    width: 200%; height: 200%;
    background: linear-gradient(135deg, transparent 40%, rgba(255,255,255,.12) 50%, transparent 60%);
    animation: shine 4s ease-in-out infinite;
}
@keyframes shine { 0%,100%{transform:translateX(-200%)} 50%{transform:translateX(200%)} }

.lsc-brand-name {
    font-family: 'Orbitron', monospace;
    font-size: .95rem; font-weight: 800;
    color: var(--txt); letter-spacing: .06em;
    line-height: 1.2;
}
.lsc-brand-name span { color: var(--violet-l); }
.lsc-brand-sub {
    font-size: .65rem; color: var(--txt-s);
    text-transform: uppercase; letter-spacing: .14em;
    margin-top: .2rem;
}

.lsc-user {
    margin: .75rem 1rem;
    padding: .6rem .9rem;
    background: rgba(139,92,246,.05);
    border: 1px solid rgba(139,92,246,.14);
    border-radius: 8px;
    display: flex; align-items: center; gap: .6rem;
}
.lsc-user-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--cyan); flex-shrink: 0;
    box-shadow: 0 0 7px var(--cyan);
    animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.6;transform:scale(.85)} }
.lsc-user-name { font-size: .82rem; font-weight: 600; color: var(--txt); }
.lsc-user-grade { font-size: .66rem; color: var(--txt-s); margin-top: .05rem; }

.lsc-nav { padding: .5rem 0 2rem; flex: 1; }
.lsc-section {
    padding: .85rem 1.4rem .3rem;
    font-size: .58rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .2em;
    color: var(--txt-m);
}
.lsc-link {
    display: flex; align-items: center; gap: .7rem;
    padding: .7rem 1.4rem;
    color: var(--txt-s); text-decoration: none;
    font-size: .85rem; font-weight: 500;
    transition: all .16s; position: relative;
    letter-spacing: .01em;
}
.lsc-link::before {
    content: '';
    position: absolute; left: 0; top: 50%;
    transform: translateY(-50%);
    width: 3px; height: 0;
    background: linear-gradient(180deg, var(--violet), var(--cyan));
    border-radius: 0 2px 2px 0;
    transition: height .16s;
}
.lsc-link i { width: 15px; text-align: center; font-size: .8rem; }
.lsc-link:hover, .lsc-link.active {
    color: var(--violet-xl);
    background: rgba(139,92,246,.07);
}
.lsc-link:hover::before, .lsc-link.active::before { height: 55%; }
.lsc-link.active { font-weight: 700; color: var(--violet-xl); }
.lsc-link.active i { color: var(--violet-l); }

.lsc-divider { height: 1px; background: var(--bdr); margin: .65rem 1.4rem; }

/* ══ MAIN ══════════════════════════════════════════════════ */
.lsc-main { margin-left: var(--sidebar-w); min-height: 100vh; position: relative; z-index: 1; }

.lsc-topbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(7,6,13,.97);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--bdr);
    padding: 1rem 1.8rem;
    display: flex; justify-content: space-between; align-items: center;
}
.lsc-topbar::after {
    content: '';
    position: absolute; bottom: 0; left: 0;
    width: 100%; height: 1px;
    background: linear-gradient(90deg,
        transparent, var(--violet), var(--cyan), var(--violet), transparent);
    opacity: .35;
}
.lsc-topbar-title {
    font-family: 'Orbitron', monospace;
    font-size: 1rem; font-weight: 800;
    color: var(--txt); letter-spacing: .06em;
    display: flex; align-items: center; gap: .65rem;
}
.lsc-topbar-title i { color: var(--violet-l); }
.lsc-topbar-sub {
    font-size: .66rem; color: var(--txt-s);
    text-transform: uppercase; letter-spacing: .1em;
    margin-top: .1rem;
}
.lsc-topbar-right { display: flex; align-items: center; gap: .65rem; }
.lsc-badge {
    display: inline-flex; align-items: center; gap: .38rem;
    padding: .34rem .9rem;
    background: rgba(139,92,246,.1);
    border: 1px solid rgba(139,92,246,.28);
    border-radius: 20px;
    font-size: .74rem; font-weight: 700;
    color: var(--violet-l); letter-spacing: .05em;
    font-family: 'Orbitron', monospace;
}
.lsc-badge-cyan {
    background: rgba(6,182,212,.08);
    border-color: rgba(6,182,212,.22);
    color: var(--cyan-l);
}

.lsc-content { padding: 1.5rem 1.8rem; }

/* ══ COMPONENTS ════════════════════════════════════════════ */
.card {
    background: var(--bg-c);
    border: 1px solid var(--bdr);
    border-radius: 12px; padding: 1.4rem;
    margin-bottom: 1.4rem;
    transition: border-color .2s;
}
.card:hover { border-color: var(--bdr-h); }

.btn {
    display: inline-flex; align-items: center; gap: .48rem;
    padding: .62rem 1.2rem; border-radius: 8px;
    font-family: 'DM Sans', sans-serif;
    font-weight: 600; font-size: .85rem;
    cursor: pointer; transition: all .16s;
    text-decoration: none; border: none;
    letter-spacing: .02em;
}
.btn-primary {
    background: linear-gradient(135deg, var(--violet), var(--violet-d));
    color: #fff;
    box-shadow: 0 3px 16px rgba(139,92,246,.35);
}
.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 5px 22px rgba(139,92,246,.5);
}
.btn-secondary {
    background: rgba(139,92,246,.09);
    color: var(--violet-l);
    border: 1px solid rgba(139,92,246,.25);
}
.btn-secondary:hover { background: rgba(139,92,246,.16); border-color: rgba(139,92,246,.4); }
.btn-ghost { background: transparent; color: var(--txt-s); border: 1px solid var(--bdr); }
.btn-ghost:hover { background: var(--bg-e); color: var(--txt); }
.btn-cyan {
    background: rgba(6,182,212,.12);
    color: var(--cyan-l);
    border: 1px solid rgba(6,182,212,.28);
}
.btn-cyan:hover { background: rgba(6,182,212,.2); }
.btn-danger { background: rgba(244,63,94,.1); color: #fda4af; border: 1px solid rgba(244,63,94,.25); }
.btn-danger:hover { background: rgba(244,63,94,.2); }
.btn-sm { padding: .38rem .82rem; font-size: .78rem; }
.btn-xs { padding: .22rem .55rem; font-size: .7rem; }

.form-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 1.1rem; }
.form-full { grid-column: 1/-1; }
.form-group { display: flex; flex-direction: column; gap: .35rem; }
.form-label {
    font-size: .64rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .13em;
    color: var(--txt-s);
}
.form-input, .form-select, .form-textarea {
    width: 100%; padding: .68rem .9rem;
    background: var(--bg); border: 1px solid rgba(139,92,246,.18);
    border-radius: 7px; color: var(--txt);
    font-family: 'DM Sans', sans-serif; font-size: .9rem;
    transition: all .16s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none; border-color: var(--violet);
    box-shadow: 0 0 0 3px rgba(139,92,246,.12);
}
.form-input::placeholder { color: var(--txt-m); }
.form-select option { background: var(--bg-e); }
.form-textarea { resize: vertical; min-height: 90px; }

.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.92);
    backdrop-filter: blur(10px);
    display: none; align-items: center; justify-content: center;
    z-index: 9999; padding: 1.5rem;
}
.modal-overlay:not(.hidden) { display: flex; }
.modal {
    background: linear-gradient(145deg, var(--bg-e), var(--bg));
    border: 1px solid rgba(139,92,246,.3);
    border-radius: 16px;
    box-shadow: 0 0 80px rgba(0,0,0,.8), 0 0 40px rgba(139,92,246,.08);
    width: 90%; max-width: 640px; max-height: 90vh;
    display: flex; flex-direction: column; overflow: hidden;
}
.modal-header {
    padding: 1.2rem 1.6rem;
    border-bottom: 1px solid var(--bdr);
    display: flex; justify-content: space-between; align-items: center;
    background: rgba(139,92,246,.04);
    position: relative;
}
.modal-header::after {
    content: '';
    position: absolute; bottom: 0; left: 0;
    width: 100%; height: 1px;
    background: linear-gradient(90deg, transparent, var(--violet), transparent);
    opacity: .4;
}
.modal-header h2 {
    font-family: 'Orbitron', monospace;
    font-size: .9rem; font-weight: 700;
    color: var(--txt); display: flex; align-items: center; gap: .65rem;
    letter-spacing: .04em;
}
.modal-header h2 i { color: var(--violet-l); }
.modal-close {
    width: 30px; height: 30px; border-radius: 7px;
    background: rgba(139,92,246,.07);
    border: 1px solid var(--bdr);
    color: var(--txt-s); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; transition: all .16s;
}
.modal-close:hover { background: rgba(244,63,94,.15); color: #fda4af; transform: rotate(90deg); }
.modal-body { padding: 1.6rem; overflow-y: auto; flex: 1; }
.modal-footer {
    padding: 1rem 1.6rem;
    border-top: 1px solid var(--bdr);
    background: rgba(0,0,0,.2);
    display: flex; justify-content: flex-end; gap: .65rem;
}

.alert {
    padding: .82rem 1.1rem; border-radius: 9px; margin-bottom: 1.3rem;
    display: flex; align-items: center; gap: .65rem;
    font-size: .88rem; font-weight: 500;
}
.alert-success { background: rgba(6,182,212,.07); border: 1px solid rgba(6,182,212,.22); color: var(--cyan-l); }
.alert-error   { background: rgba(244,63,94,.08); border: 1px solid rgba(244,63,94,.22); color: #fda4af; }
.alert-info    { background: rgba(139,92,246,.08); border: 1px solid rgba(139,92,246,.22); color: var(--violet-xl); }

.badge {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .2rem .55rem; border-radius: 5px;
    font-size: .64rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
}
.badge-violet { background: rgba(139,92,246,.12); color: var(--violet-xl); border: 1px solid rgba(139,92,246,.22); }
.badge-cyan   { background: rgba(6,182,212,.1);   color: var(--cyan-l);    border: 1px solid rgba(6,182,212,.2); }
.badge-green  { background: rgba(34,197,94,.1);   color: #86efac;          border: 1px solid rgba(34,197,94,.2); }
.badge-red    { background: rgba(244,63,94,.1);   color: #fda4af;          border: 1px solid rgba(244,63,94,.2); }
.badge-gray   { background: rgba(100,116,139,.1); color: #94a3b8;          border: 1px solid rgba(100,116,139,.2); }

.mono { font-family: 'DM Mono', monospace; }
.hidden { display: none !important; }

@media (max-width:1100px) {
    .lsc-sidebar { transform: translateX(-100%); }
    .lsc-main { margin-left: 0; }
    .form-grid { grid-template-columns: 1fr; }
}
</style>
<?php echo $__panelThemeCSS ?? ''; ?>
</head>
<body>

<aside class="lsc-sidebar">
    <div class="lsc-brand">
        <div class="lsc-logo"><i class="fas fa-car-side"></i></div>
        <div class="lsc-brand-name">LS <span>CUSTOM</span></div>
        <div class="lsc-brand-sub">Los Santos Customs</div>
    </div>

    <div class="lsc-user">
        <div class="lsc-user-dot"></div>
        <div>
            <div class="lsc-user-name"><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
            <div class="lsc-user-grade"><?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?></div>
        </div>
    </div>

    <nav class="lsc-nav">
        <?php
        $__sec = '';
        foreach ($__lscNavPages as $__p):
            if (!empty($__p['section']) && $__p['section'] !== $__sec):
                $__sec = $__p['section'];
                echo '<div class="lsc-section">' . htmlspecialchars($__sec) . '</div>';
            endif;
            $__act = ($currentPage === $__p['file']) ? ' active' : '';
        ?>
        <a href="<?php echo $__p['file']; ?>.php" class="lsc-link<?php echo $__act; ?>">
            <i class="<?php echo $__p['icon']; ?>"></i>
            <span><?php echo htmlspecialchars($__p['label']); ?></span>
        </a>
        <?php endforeach; ?>

        <div class="lsc-divider"></div>
        <?php if (hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])): ?>
        <a href="<?php echo SITE_URL; ?>/pages/staff/dashboard.php" class="lsc-link">
            <i class="fas fa-layer-group"></i><span>Panel Staff</span>
        </a>
        <?php endif; ?>
        
        <?php
        // -- Acces info_vente (independant de faction_helper) ------
        if (!isset($__ivRes)) {
            $__ivRes = false;
            if (hasRole(['*','Administrateur','Developpeur','Staff','Moderateur','Helper'])) {
                $__ivRes = true;
            } elseif (($_SESSION['user_faction'] ?? '') === 'LS_CUSTOM') {
                try {
                    $__ivDb2 = Database::getInstance()->getConnection();
                    $__ivGrd = $_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? '';
                    if (!$__ivGrd) {
                        $__ivSt = $__ivDb2->prepare('SELECT COALESCE(faction_grade,faction_rank,\'\') FROM users WHERE id=? LIMIT 1');
                        $__ivSt->execute([$_SESSION['user_id'] ?? 0]);
                        $__ivGrd = (string)($__ivSt->fetchColumn() ?: '');
                    }
                    if ($__ivGrd) {
                        $__ivQ = $__ivDb2->prepare(
                            'SELECT fg.can_recruit FROM faction_grades fg'
                            .' JOIN factions f ON f.id=fg.faction_id'
                            .' WHERE f.faction_key=? AND fg.name=? LIMIT 1'
                        );
                        $__ivQ->execute(['LS_CUSTOM', $__ivGrd]);
                        $__ivR = $__ivQ->fetch(PDO::FETCH_ASSOC);
                        if ($__ivR && !empty($__ivR['can_recruit'])) $__ivRes = true;
                    }
                } catch (Exception $__ivEx) {}
            }
        }
        if ($__ivRes):
        ?>
        <a href="info_vente.php" class="lsc-link<?php echo ($currentPage??'')==='info_vente'?' active':''; ?>">
            <i class="fas fa-trophy"></i><span>Classement Ventes</span>
        </a>
        <?php endif; ?>
<a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="lsc-link">
            <i class="fas fa-home"></i><span>Accueil MDT</span>
        </a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="lsc-link">
            <i class="fas fa-right-from-bracket"></i><span>Déconnexion</span>
        </a>
    </nav>
</aside>

<main class="lsc-main">
    <div class="lsc-topbar">
        <div>
            <div class="lsc-topbar-title">
                <i class="fas fa-<?php echo $pageIcon ?? 'car-side'; ?>"></i>
                <?php echo htmlspecialchars($pageTitle ?? 'LS Custom'); ?>
            </div>
            <div class="lsc-topbar-sub">Los Santos Customs — Panel interne</div>
        </div>
        <div class="lsc-topbar-right">
            <?php
            $__grade = htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? '');
            if ($__grade): ?>
            <span class="lsc-badge lsc-badge-cyan"><i class="fas fa-id-badge"></i><?php echo $__grade; ?></span>
            <?php endif; ?>
            <span class="lsc-badge"><i class="fas fa-car-side"></i>LS CUSTOM</span>
        </div>
    </div>
    <div class="lsc-content">