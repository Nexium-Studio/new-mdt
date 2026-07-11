<?php
/**
 * En-tête du panel de division (générique, toutes factions).
 *
 * Attend : $division (tableau), $view (string), $canManage (bool),
 *          $currentUserId (int), $panelKey (string), $pageTitle (string).
 */
if (!isset($division)) exit;

$divId      = (int)$division['id'];
$divName    = (string)$division['name'];
$divAbbr    = (string)($division['abbreviation'] ?? '');
$divIcon    = (string)($division['icon'] ?? 'layer-group');
$divColor   = (string)($division['color'] ?? '#64748b');
$divDesc    = (string)($division['description'] ?? '');
$factionKey = (string)$division['f_key'];
$factionNm  = (string)$division['f_name'];

$baseUrl = SITE_URL . '/pages/divisions/panel.php?key=' . urlencode($division['panel_key']);

$__views = [
    ['id' => 'dashboard',  'label' => 'Dashboard',   'icon' => 'fas fa-chart-pie',       'section' => ''],
    ['id' => 'effectifs',  'label' => 'Effectifs',   'icon' => 'fas fa-users',           'section' => 'Organisation'],
    ['id' => 'grades',     'label' => 'Grades',      'icon' => 'fas fa-medal',           'section' => ''],
    ['id' => 'annonces',   'label' => 'Annonces',    'icon' => 'fas fa-bullhorn',        'section' => 'Communication'],
    ['id' => 'documents',  'label' => 'Documents',   'icon' => 'fas fa-folder-open',     'section' => ''],
];

// ── Rapports (capabilités optionnelles) ────────────────────────────────────
if (div_has_capability($division, 'create_own_reports')) {
    $__views[] = ['id'=>'reports','label'=>'Rapports','icon'=>'fas fa-file-lines','section'=>'Rapports'];
}
if (div_has_capability($division, 'view_lspd_reports')) {
    $__views[] = ['id'=>'reports_lspd','label'=>'Rapports LSPD','icon'=>'fas fa-shield','section'=>''];
}

// ── Administration (toujours en dernier) ───────────────────────────────────
$__views[] = ['id'=>'logs','label'=>'Journal','icon'=>'fas fa-clipboard-list','section'=>'Administration'];

$userInitial = mdt_session_display_initial();
$userName    = mdt_session_display_name() ?: 'Utilisateur';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(($pageTitle ?? $divName)); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=IBM+Plex+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        :root{
            --accent:<?php echo htmlspecialchars($divColor); ?>;
            --accent-fade:<?php echo htmlspecialchars($divColor); ?>22;
            --accent-soft:<?php echo htmlspecialchars($divColor); ?>14;
            --accent-bright:<?php echo htmlspecialchars($divColor); ?>cc;
            --bg:#03080f;--bg-c:#060d1a;--bg-e:#0a1020;--bg-ov:#0e1830;
            --bdr:rgba(148,163,184,.12);--bdr-h:rgba(148,163,184,.3);
            --txt:#e8edf5;--txt-s:#94a3b8;--txt-m:#475569;
            --green:#10b981;--red:#ef4444;--yellow:#f59e0b;--blue:#3b82f6;
        }
        html,body{height:100%}
        body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:var(--bg);color:var(--txt);overflow-x:hidden;font-size:14px}
        a{color:inherit;text-decoration:none}

        /* ═══ SIDEBAR ═══ */
        .d-sb{position:fixed;left:0;top:0;width:262px;height:100vh;background:linear-gradient(180deg,#050c1e 0%,#030810 55%,var(--bg) 100%);border-right:1px solid var(--bdr);overflow-y:auto;z-index:1000;display:flex;flex-direction:column}
        .d-sb::-webkit-scrollbar{width:4px}
        .d-sb::-webkit-scrollbar-thumb{background:rgba(148,163,184,.2)}

        .d-sb-hd{padding:1.5rem 1.3rem 1.15rem;border-bottom:1px solid var(--bdr);position:relative}
        .d-sb-hd::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,transparent,var(--accent-bright),transparent);opacity:.6}
        .d-logo{display:flex;align-items:center;gap:.85rem}
        .d-logo-ico{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;color:#fff;background:linear-gradient(135deg,var(--accent),var(--accent-bright));box-shadow:0 4px 20px var(--accent-fade)}
        .d-logo-txt{font-family:'Syne',sans-serif;font-weight:800;font-size:.98rem;color:#fff;letter-spacing:.4px;line-height:1.1}
        .d-logo-sub{font-size:.62rem;color:var(--txt-s);text-transform:uppercase;letter-spacing:1.3px;margin-top:.22rem;font-weight:600}

        .d-faction-tag{display:inline-flex;align-items:center;gap:.35rem;margin-top:.7rem;padding:.2rem .55rem;background:var(--accent-soft);border:1px solid var(--accent-fade);border-radius:20px;font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--accent)}

        .d-nav{padding:.8rem 0;flex:1}
        .d-nav-sec{padding:.85rem 1.3rem .35rem;font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.2em;color:var(--txt-m)}
        .d-nav-item{display:flex;align-items:center;gap:.7rem;padding:.7rem 1.3rem;color:var(--txt-s);font-weight:500;font-size:.83rem;transition:all .18s;position:relative;border-left:3px solid transparent}
        .d-nav-item i{width:18px;text-align:center;font-size:.85rem}
        .d-nav-item:hover{color:var(--txt);background:rgba(148,163,184,.05)}
        .d-nav-item.active{color:#fff;background:var(--accent-soft);border-left-color:var(--accent)}
        .d-nav-item.active i{color:var(--accent)}

        .d-nav-divider{height:1px;background:linear-gradient(90deg,transparent,var(--bdr),transparent);margin:.8rem 1.3rem}

        .d-sb-ft{margin-top:auto;padding:1rem 1.3rem;border-top:1px solid var(--bdr);background:rgba(0,0,0,.15)}
        .d-user{display:flex;align-items:center;gap:.65rem}
        .d-user-ava{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent-bright));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff}
        .d-user-nm{font-size:.82rem;font-weight:600;color:var(--txt)}
        .d-user-rl{font-size:.62rem;color:var(--txt-s);text-transform:uppercase;letter-spacing:.1em;margin-top:.1rem}

        /* ═══ MAIN ═══ */
        .d-main{margin-left:262px;min-height:100vh}
        .d-topbar{position:sticky;top:0;z-index:100;background:rgba(6,13,26,.9);backdrop-filter:blur(10px);border-bottom:1px solid var(--bdr);padding:.95rem 1.6rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}
        .d-topbar h1{font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;color:#fff;letter-spacing:.5px}
        .d-topbar .d-breadcrumb{font-size:.72rem;color:var(--txt-s);text-transform:uppercase;letter-spacing:.12em}
        .d-topbar .d-breadcrumb .sep{margin:0 .35rem;opacity:.5}
        .d-topbar-badges{display:flex;gap:.4rem;align-items:center}
        .d-badge{padding:.28rem .6rem;border-radius:7px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em}
        .d-badge-accent{background:var(--accent-soft);color:var(--accent);border:1px solid var(--accent-fade)}
        .d-badge-staff{background:rgba(245,158,11,.1);color:#fde68a;border:1px solid rgba(245,158,11,.22)}

        .d-content{padding:1.6rem;max-width:1400px}

        /* ═══ CARDS / UI ═══ */
        .d-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.2rem;margin-bottom:1rem}
        .d-card-hd{display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:1rem}
        .d-card-tt{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#fff;display:flex;align-items:center;gap:.5rem}
        .d-card-tt i{color:var(--accent)}
        .d-card-sub{font-size:.75rem;color:var(--txt-s);margin-top:.2rem}

        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.8rem;margin-bottom:1.2rem}
        .stat{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1rem 1.15rem;position:relative;overflow:hidden}
        .stat::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);opacity:.7}
        .stat-lbl{font-size:.62rem;color:var(--txt-s);text-transform:uppercase;letter-spacing:.15em;font-weight:700}
        .stat-val{font-family:'IBM Plex Mono',monospace;font-size:1.6rem;font-weight:700;color:#fff;margin-top:.3rem}
        .stat-sub{font-size:.7rem;color:var(--txt-s);margin-top:.25rem}

        .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem .95rem;border-radius:8px;border:1px solid var(--bdr);background:var(--bg-ov);color:var(--txt);font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none}
        .btn:hover{border-color:var(--accent);color:#fff}
        .btn-sm{padding:.4rem .7rem;font-size:.72rem}
        .btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}
        .btn-primary:hover{filter:brightness(1.1);color:#fff}
        .btn-ghost{background:transparent;border-color:var(--bdr)}
        .btn-danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.25);color:#fca5a5}
        .btn-danger:hover{background:rgba(239,68,68,.2);color:#fff}
        .btn-success{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.25);color:#6ee7b7}
        .btn-success:hover{background:rgba(16,185,129,.2);color:#fff}

        .form-label{display:block;font-size:.7rem;font-weight:600;color:var(--txt-s);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.35rem}
        .form-input,.form-select,.form-textarea{width:100%;padding:.6rem .75rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;color:var(--txt);font-size:.82rem;font-family:inherit}
        .form-input:focus,.form-select:focus,.form-textarea:focus{outline:none;border-color:var(--accent)}
        .form-textarea{min-height:90px;resize:vertical}
        .form-row{display:flex;gap:.6rem;flex-wrap:wrap}
        .form-group{margin-bottom:.8rem;flex:1;min-width:200px}

        .alert{padding:.7rem 1rem;border-radius:8px;font-size:.8rem;margin-bottom:1rem;display:flex;align-items:center;gap:.55rem}
        .alert-success{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);color:#6ee7b7}
        .alert-error{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#fca5a5}
        .alert-info{background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.25);color:#93c5fd}

        .table{width:100%;border-collapse:collapse;font-size:.83rem}
        .table th{text-align:left;padding:.55rem .75rem;font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);font-weight:700;border-bottom:1px solid var(--bdr)}
        .table td{padding:.65rem .75rem;border-bottom:1px solid var(--bdr);color:var(--txt)}
        .table tr:hover td{background:rgba(148,163,184,.03)}

        .chip{display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .5rem;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:var(--bg-ov);border:1px solid var(--bdr);color:var(--txt-s)}
        .chip.accent{background:var(--accent-soft);border-color:var(--accent-fade);color:var(--accent)}
        .chip.ok{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.25);color:#6ee7b7}
        .chip.warn{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.25);color:#fde68a}
        .chip.danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.25);color:#fca5a5}

        .empty-state{text-align:center;padding:2rem;color:var(--txt-s);font-size:.82rem}
        .empty-state i{font-size:2rem;opacity:.25;display:block;margin-bottom:.55rem}

        @media (max-width:1024px){
            .d-sb{transform:translateX(-100%);transition:transform .25s}
            .d-sb.show{transform:translateX(0)}
            .d-main{margin-left:0}
        }
    </style>
</head>
<body>

<aside class="d-sb" id="divSidebar">
    <div class="d-sb-hd">
        <div class="d-logo">
            <div class="d-logo-ico"><i class="fas fa-<?php echo htmlspecialchars($divIcon); ?>"></i></div>
            <div>
                <div class="d-logo-txt"><?php echo htmlspecialchars($divName); ?></div>
                <div class="d-logo-sub">
                    <?php echo htmlspecialchars($divAbbr ?: $division['division_key']); ?>
                </div>
            </div>
        </div>
        <span class="d-faction-tag">
            <i class="fas fa-shield-halved"></i>
            <?php echo htmlspecialchars($factionNm); ?>
        </span>
    </div>

    <nav class="d-nav">
        <?php
        $__prevSec = null;
        foreach ($__views as $v):
            if (!empty($v['section']) && $v['section'] !== $__prevSec) {
                echo '<div class="d-nav-sec">' . htmlspecialchars($v['section']) . '</div>';
                $__prevSec = $v['section'];
            }
            $isActive = ($v['id'] === $view);
        ?>
        <a href="<?php echo htmlspecialchars($baseUrl . '&view=' . $v['id']); ?>"
           class="d-nav-item <?php echo $isActive ? 'active' : ''; ?>">
            <i class="<?php echo htmlspecialchars($v['icon']); ?>"></i>
            <span><?php echo htmlspecialchars($v['label']); ?></span>
        </a>
        <?php endforeach; ?>

        <div class="d-nav-divider"></div>

        <?php
        $factionHome = [
            'LSPD'   => '/pages/lspd/dashboard.php',
            'LSSD'   => '/pages/lssd/dashboard.php',
            'SAMS'   => '/pages/sams/dashboard.php',
            'USSS'   => '/pages/usss/dashboard.php',
            'GRUPP6' => '/pages/grupp6/dashboard.php',
        ];
        $homeUrl = $factionHome[$factionKey] ?? '/pages/dashboard.php';
        ?>
        <a href="<?php echo SITE_URL . htmlspecialchars($homeUrl); ?>" class="d-nav-item">
            <i class="fas fa-arrow-left"></i>
            <span>Retour <?php echo htmlspecialchars($factionNm); ?></span>
        </a>
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="d-nav-item">
            <i class="fas fa-house"></i>
            <span>Accueil MDT</span>
        </a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="d-nav-item">
            <i class="fas fa-right-from-bracket"></i>
            <span>Déconnexion</span>
        </a>
    </nav>

    <div class="d-sb-ft">
        <div class="d-user">
            <div class="d-user-ava"><?php echo htmlspecialchars($userInitial); ?></div>
            <div>
                <div class="d-user-nm"><?php echo htmlspecialchars($userName); ?></div>
                <div class="d-user-rl">
                    <?php echo htmlspecialchars($_SESSION['user_faction_rank'] ?? $_SESSION['user_faction'] ?? '—'); ?>
                </div>
            </div>
        </div>
    </div>
</aside>

<main class="d-main">
    <div class="d-topbar">
        <div>
            <h1><?php echo htmlspecialchars($divName); ?></h1>
            <div class="d-breadcrumb">
                <span><?php echo htmlspecialchars($factionNm); ?></span>
                <span class="sep">›</span>
                <span>Divisions</span>
                <span class="sep">›</span>
                <span style="color:var(--accent)"><?php echo htmlspecialchars(ucfirst($view)); ?></span>
            </div>
        </div>
        <div class="d-topbar-badges">
            <?php if ($canManage): ?>
            <span class="d-badge d-badge-accent"><i class="fas fa-shield-check"></i> Gestion</span>
            <?php endif; ?>
            <?php if (div_is_staff_bypass()): ?>
            <span class="d-badge d-badge-staff"><i class="fas fa-user-shield"></i> Staff</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-content">

    <?php if (!empty($_SESSION['div_flash'])):
        $f = $_SESSION['div_flash']; unset($_SESSION['div_flash']); ?>
    <div class="alert alert-<?php echo htmlspecialchars($f['type'] ?? 'info'); ?>">
        <i class="fas fa-<?php echo ($f['type'] ?? '') === 'error' ? 'triangle-exclamation' : 'circle-info'; ?>"></i>
        <?php echo htmlspecialchars($f['msg'] ?? ''); ?>
    </div>
    <?php endif; ?>
