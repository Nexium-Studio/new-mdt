<?php
// pages/academy/includes/header.php
if (!defined('ACADEMY_PUBLIC')) {
    define('ACADEMY_PUBLIC', true);
}

$__academyCurrent = basename($_SERVER['PHP_SELF'], '.php');
$__academySiteName = 'LysoriaMDT';
try {
    $db = Database::getInstance()->getConnection();
    $row = $db->query("SELECT setting_value FROM admin_settings WHERE setting_key='site_name' LIMIT 1")->fetch();
    if ($row && $row['setting_value']) $__academySiteName = $row['setting_value'];
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle ?? 'Académie'); ?> — <?php echo htmlspecialchars($__academySiteName); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<style>
:root{--bg:#03070f;--bg-2:#080f1d;--bg-card:rgba(10,17,32,.7);--border:rgba(255,255,255,.07);--border-2:rgba(255,255,255,.14);--text:#e2e8f0;--muted:#475569;--muted-2:#64748b;--blue:#3b82f6;--purple:#8b5cf6;--green:#10b981;--red:#ef4444;--yellow:#eab308}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5;display:flex;flex-direction:column;background-image:radial-gradient(ellipse 80% 50% at 20% -10%,rgba(139,92,246,.08) 0%,transparent 60%),radial-gradient(ellipse 60% 40% at 80% 110%,rgba(59,130,246,.06) 0%,transparent 60%)}
h1,h2,h3,h4{font-family:'Syne',sans-serif;font-weight:700;line-height:1.2}
a{color:inherit;text-decoration:none}
.container{max-width:1400px;margin:0 auto;padding:0 2rem;width:100%}
header.acad-head{background:rgba(3,7,15,.8);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.acad-head-inner{height:64px;display:flex;justify-content:space-between;align-items:center}
.acad-brand{font-family:'Syne',sans-serif;font-size:1.15rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px}
.acad-brand-icon{width:34px;height:34px;background:linear-gradient(135deg,#8b5cf6,#6366f1);border-radius:9px;display:grid;place-items:center;font-size:15px;color:#fff}
.acad-head-right{display:flex;align-items:center;gap:12px}
.acad-user-chip{display:flex;align-items:center;gap:10px;padding:6px 14px 6px 6px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:50px}
.acad-avatar{width:32px;height:32px;border-radius:50%;background:var(--purple);display:grid;place-items:center;font-weight:700;font-size:13px;overflow:hidden;flex-shrink:0}
.acad-avatar img{width:100%;height:100%;object-fit:cover}
.acad-uinfo{line-height:1.2}
.acad-uname{font-size:.83rem;font-weight:600}
.acad-urole{font-size:.68rem;color:var(--muted-2)}
.acad-hbtn{width:36px;height:36px;border-radius:9px;border:1px solid var(--border);background:rgba(255,255,255,.03);color:var(--muted-2);display:grid;place-items:center;transition:all .2s;font-size:14px}
.acad-hbtn:hover{background:rgba(255,255,255,.08);border-color:var(--border-2);color:var(--text)}
.acad-hbtn.logout:hover{color:var(--red);border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.08)}
main{padding:2.5rem 0 5rem;flex:1}
footer.acad-foot{border-top:1px solid var(--border);padding:1.5rem 0;margin-top:auto}
.acad-foot-inner{display:flex;justify-content:space-between;align-items:center;font-size:.78rem;color:var(--muted-2)}
.page-hero{margin-bottom:2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.page-eyebrow{font-size:.75rem;color:var(--purple);text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:.5rem;display:flex;align-items:center;gap:.4rem}
.page-title{font-size:2.1rem;margin-bottom:.3rem}
.page-sub{color:var(--muted-2);font-size:.9rem}

/* Cards / tabs / badges (repris de l'espace-perso) */
.ep-card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;overflow:hidden;backdrop-filter:blur(8px)}
.ep-card-hd{padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.ep-card-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;display:flex;align-items:center;gap:.5rem}
.ep-card-bd{padding:1.25rem}
.ep-grid{display:grid;gap:1.25rem}
.ep-btn{display:inline-flex;align-items:center;gap:.45rem;padding:.55rem 1rem;border-radius:9px;font-weight:600;font-size:.85rem;border:1px solid var(--border);background:rgba(255,255,255,.03);color:var(--text);cursor:pointer;transition:all .2s;text-decoration:none}
.ep-btn:hover{border-color:var(--border-2);background:rgba(255,255,255,.06)}
.ep-btn-primary{background:var(--blue);border-color:var(--blue);color:#fff}
.ep-btn-primary:hover{background:#2563eb}
.ep-btn-purple{background:linear-gradient(135deg,#8b5cf6,#6366f1);border-color:transparent;color:#fff}
.ep-btn-purple:hover{opacity:.92;transform:translateY(-1px)}
.ep-btn-sm{padding:.4rem .75rem;font-size:.78rem}
.ep-btn-outline{background:transparent}
.ep-label{font-size:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted-2);margin-bottom:.35rem}
.ep-input,.ep-textarea,.ep-select{width:100%;padding:.55rem .75rem;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:.88rem;outline:none;transition:border-color .2s}
.ep-input:focus,.ep-textarea:focus,.ep-select:focus{border-color:var(--purple);box-shadow:0 0 0 3px rgba(139,92,246,.15)}
.ep-textarea{resize:vertical;min-height:100px}
.ep-alert{padding:.7rem 1rem;border-radius:9px;font-size:.85rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.5rem;border-left:3px solid}
.ep-alert.ok{background:rgba(16,185,129,.08);border-color:var(--green);color:#a7f3d0}
.ep-alert.err{background:rgba(239,68,68,.08);border-color:var(--red);color:#fecaca}
.ep-alert.info{background:rgba(59,130,246,.08);border-color:var(--blue);color:#bfdbfe}
.ep-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .55rem;border-radius:6px;font-size:.7rem;font-weight:700;letter-spacing:.04em}
.ep-badge.ok{background:rgba(16,185,129,.15);color:#6ee7b7}
.ep-badge.warn{background:rgba(245,158,11,.15);color:#fcd34d}
.ep-badge.muted{background:rgba(100,116,139,.14);color:#94a3b8}

/* Faction grid */
.fac-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem}
.fac-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:1.25rem;display:flex;flex-direction:column;gap:.85rem;transition:transform .2s,border-color .2s}
.fac-card:hover{transform:translateY(-3px);border-color:var(--border-2)}
.fac-head{display:flex;align-items:center;gap:.75rem}
.fac-ic{width:44px;height:44px;border-radius:11px;display:grid;place-items:center;font-size:1.15rem;color:#fff}
.fac-name{font-family:'Syne',sans-serif;font-weight:700;font-size:1.05rem}
.fac-sub{font-size:.72rem;color:var(--muted-2)}
.fac-actions{display:flex;gap:.5rem;margin-top:auto}
.fac-actions .ep-btn{flex:1;justify-content:center}

/* Form dyn */
.dyn-form{display:flex;flex-direction:column;gap:1rem}
.dyn-field label{display:block;font-size:.82rem;font-weight:600;margin-bottom:.35rem;color:var(--text)}
.dyn-field .req{color:var(--red);margin-left:.2rem}

/* QCM */
.qcm-progress{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
.qcm-bar{height:6px;background:var(--border);border-radius:3px;overflow:hidden;flex:1;margin:0 1rem}
.qcm-bar-fill{height:100%;background:linear-gradient(90deg,#8b5cf6,#6366f1);transition:width .4s}
.qcm-q{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:1.5rem;margin-bottom:1rem}
.qcm-q-title{font-size:1rem;font-weight:600;margin-bottom:1rem}
.qcm-opts{display:flex;flex-direction:column;gap:.5rem}
.qcm-opt{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:all .2s;background:rgba(255,255,255,.02)}
.qcm-opt:hover{border-color:var(--purple);background:rgba(139,92,246,.04)}
.qcm-opt input{margin:0}
.qcm-opt.selected{border-color:var(--purple);background:rgba(139,92,246,.1)}
.qcm-timer{font-family:'DM Mono',monospace;font-weight:700;color:var(--yellow)}

@media(max-width:768px){
  .acad-uinfo{display:none}
  .container{padding:0 1rem}
  .page-title{font-size:1.6rem}
  .fac-grid{grid-template-columns:1fr}
}
</style>
<?php if (!empty($extraCss)): ?><style><?php echo $extraCss; ?></style><?php endif; ?>
</head>
<body>

<header class="acad-head">
  <div class="container acad-head-inner">
    <a href="<?php echo SITE_URL; ?>/pages/academy/dashboard.php" class="acad-brand">
      <span class="acad-brand-icon"><i class="fa-solid fa-graduation-cap"></i></span>
      Académie
    </a>
    <div class="acad-head-right">
      <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="acad-hbtn" title="MDT">
        <i class="fa-solid fa-house"></i>
      </a>
      <a href="<?php echo SITE_URL; ?>/pages/espace-perso.php" class="acad-hbtn" title="Espace perso">
        <i class="fa-solid fa-user"></i>
      </a>
      <div class="acad-user-chip">
        <div class="acad-avatar">
          <?php if (!empty($_SESSION['user_avatar'])): ?>
            <img src="<?php echo htmlspecialchars($_SESSION['user_avatar']); ?>" alt="">
          <?php else: ?>
            <?php echo htmlspecialchars(strtoupper(substr($_SESSION['user_username'] ?? 'U', 0, 1))); ?>
          <?php endif; ?>
        </div>
        <div class="acad-uinfo">
          <div class="acad-uname"><?php echo htmlspecialchars($_SESSION['user_username'] ?? 'Utilisateur'); ?></div>
          <div class="acad-urole"><?php echo htmlspecialchars($_SESSION['user_role'] ?? 'Citoyen'); ?></div>
        </div>
      </div>
      <a href="<?php echo SITE_URL; ?>/logout.php" class="acad-hbtn logout" title="Déconnexion">
        <i class="fa-solid fa-power-off"></i>
      </a>
    </div>
  </div>
</header>

<main>
  <div class="container">
