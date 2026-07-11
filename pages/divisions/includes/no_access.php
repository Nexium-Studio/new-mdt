<?php
require_once __DIR__ . '/../../../config/config.php';
$divName = htmlspecialchars($division['name'] ?? 'Division');
$factionName = htmlspecialchars($division['f_name'] ?? '');
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="UTF-8"><title>Accès refusé — <?php echo $divName; ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
body{font-family:system-ui,sans-serif;background:#03080f;color:#e8edf5;min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0}
.box{max-width:520px;text-align:center;padding:2.5rem;background:rgba(239,68,68,.04);border:1px solid rgba(239,68,68,.25);border-radius:14px}
h1{font-size:1.4rem;margin:1rem 0 .5rem}
p{color:#94a3b8;margin-bottom:1.2rem;font-size:.88rem;line-height:1.5}
.hint{font-size:.78rem;color:#64748b;background:rgba(148,163,184,.05);border:1px solid rgba(148,163,184,.1);padding:.75rem;border-radius:8px;margin:1rem 0;text-align:left}
a{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.2rem;background:#3b82f6;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;margin:.2rem}
a.ghost{background:transparent;color:#94a3b8;border:1px solid rgba(148,163,184,.2)}
i.big{font-size:2.5rem;color:#ef4444}
</style></head><body>
<div class="box">
    <i class="fas fa-lock big"></i>
    <h1>Accès refusé</h1>
    <p>Vous n'êtes pas membre de la division <strong><?php echo $divName; ?></strong> (<?php echo $factionName; ?>).</p>
    <div class="hint">
        <i class="fas fa-circle-info"></i> Pour accéder à ce panel, vous devez d'abord être ajouté à cette division par un responsable autorisé.
    </div>
    <a href="<?php echo SITE_URL; ?>/pages/dashboard.php"><i class="fas fa-home"></i> Accueil MDT</a>
    <?php
    $factionHome = [
        'LSPD' => '/pages/lspd/dashboard.php', 'LSSD' => '/pages/lssd/dashboard.php',
        'SAMS' => '/pages/sams/dashboard.php', 'USSS' => '/pages/usss/dashboard.php',
        'GRUPP6' => '/pages/grupp6/dashboard.php',
    ];
    $h = $factionHome[$division['f_key'] ?? ''] ?? null;
    if ($h): ?>
        <a class="ghost" href="<?php echo SITE_URL . $h; ?>"><i class="fas fa-arrow-left"></i> Retour <?php echo $factionName; ?></a>
    <?php endif; ?>
</div></body></html>
