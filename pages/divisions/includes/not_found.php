<?php
require_once __DIR__ . '/../../../config/config.php';
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="UTF-8"><title>Division introuvable</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
body{font-family:system-ui,sans-serif;background:#03080f;color:#e8edf5;min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0}
.box{max-width:480px;text-align:center;padding:2.5rem;background:rgba(255,255,255,.02);border:1px solid rgba(148,163,184,.15);border-radius:14px}
h1{font-size:1.3rem;margin:1rem 0 .5rem}
p{color:#94a3b8;margin-bottom:1.5rem;font-size:.9rem}
a{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.2rem;background:#3b82f6;color:#fff;border-radius:8px;text-decoration:none;font-weight:600}
i.big{font-size:2.5rem;color:#64748b}
</style></head><body>
<div class="box">
    <i class="fas fa-compass-slash big"></i>
    <h1>Division introuvable</h1>
    <p>Cette division n'existe pas ou a été désactivée. Le panel_key fourni ne correspond à aucune division.</p>
    <a href="<?php echo SITE_URL; ?>/pages/dashboard.php"><i class="fas fa-home"></i> Retour au tableau de bord</a>
</div></body></html>
