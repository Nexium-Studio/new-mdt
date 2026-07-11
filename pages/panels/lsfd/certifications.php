<?php
/**
 * §3 — Certifications & Formations LSFD (référence + liens Académie)
 * Le suivi de progression, les QCM et l'historique des formations sont gérés par
 * l'Académie Centrale. Cette page liste les certifications de référence et lie
 * vers les modules academy-central / academy.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);

// Certifications de référence (cahier des charges)
$certs = [
    ['FFI', 'Firefighter I — base obligatoire', 'fa-fire', 'red'],
    ['FFII', 'Firefighter II — combat structurel avancé', 'fa-fire-flame-curved', 'red'],
    ['EMT-B', 'Emergency Medical Technician Basic', 'fa-kit-medical', 'orange'],
    ['EMT-P', 'Paramedic — soins avancés préhospitaliers', 'fa-heart-pulse', 'orange'],
    ['Hazmat Awareness', 'Reconnaissance matières dangereuses', 'fa-radiation', 'yellow'],
    ['Hazmat Operations', 'Intervention défensive HazMat', 'fa-radiation', 'yellow'],
    ['Hazmat Tech', 'Intervention offensive HazMat', 'fa-biohazard', 'yellow'],
    ['TRT', 'Technical Rescue Technician (sauvetage spécialisé)', 'fa-helmet-safety', 'blue'],
    ['Swift Water', 'Sauvetage en eaux vives', 'fa-water', 'blue'],
    ['High Angle', 'Sauvetage en hauteur / rappel', 'fa-mountain', 'blue'],
    ['Confined Space', 'Espaces confinés', 'fa-box-archive', 'blue'],
    ['Driver/Operator', 'Qualification pour conduire les camions', 'fa-truck', 'gray'],
    ['Fire Investigator', "Enquête sur les causes d'incendie", 'fa-magnifying-glass-chart', 'purple'],
    ['Incident Commander', "Commandement d'incident", 'fa-star', 'purple'],
    ['Instructor', 'Former les autres agents', 'fa-chalkboard-user', 'green'],
];

$pageTitle = 'Certifications';
include __DIR__ . '/includes/header.php';
?>

<div class="lf-pagehead">
  <div>
    <div class="lf-ph-title"><i class="fas fa-award"></i> Certifications & Formations</div>
    <div class="lf-ph-sub">Référentiel LSFD — suivi & QCM gérés par l'Académie Centrale</div>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/formations_lsfd.php" class="lf-btn lf-btn-primary"><i class="fas fa-graduation-cap"></i> Formations</a>
    <a href="<?php echo SITE_URL; ?>/pages/academy/qcm_lsfd.php" class="lf-btn lf-btn-ghost"><i class="fas fa-list-check"></i> QCM</a>
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/documents_lsfd.php" class="lf-btn lf-btn-ghost"><i class="fas fa-folder-open"></i> Documents</a>
  </div>
</div>

<div class="lf-card">
  <div class="lf-card-h"><h3><i class="fas fa-certificate"></i> Certifications disponibles</h3></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.9rem">
    <?php foreach ($certs as $c): ?>
      <div style="display:flex;gap:.85rem;align-items:flex-start;padding:1rem;border:1px solid rgba(100,116,139,.14);border-radius:12px;background:rgba(15,18,28,.4)">
        <span style="width:40px;height:40px;border-radius:10px;flex-shrink:0;background:rgba(var(--panel-color-rgb,220,38,38),.12);color:var(--panel-color,#dc2626);display:grid;place-items:center;font-size:1.05rem"><i class="fas <?php echo $c[2]; ?>"></i></span>
        <div>
          <div style="font-weight:700;color:#e8edf5;display:flex;align-items:center;gap:.5rem"><?php echo htmlspecialchars($c[0]); ?> <span class="lf-badge <?php echo $c[3]; ?>" style="font-size:.6rem">CERT</span></div>
          <div style="color:#94a3b8;font-size:.82rem;margin-top:.25rem;line-height:1.45"><?php echo htmlspecialchars($c[1]); ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="lf-card">
  <div class="lf-card-h"><h3><i class="fas fa-book-open-reader"></i> Module formation</h3></div>
  <p style="color:#94a3b8;line-height:1.7;margin-bottom:1rem">
    Le catalogue des formations (avec prérequis de certifications), le suivi de progression par agent,
    les QCM de validation et l'historique des formations passées sont intégrés à l'<strong>Académie Centrale</strong> du MDT.
  </p>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap">
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/formations_lsfd.php" class="lf-btn lf-btn-ghost"><i class="fas fa-list"></i> Catalogue & prérequis</a>
    <a href="<?php echo SITE_URL; ?>/pages/academy/qcm_lsfd.php" class="lf-btn lf-btn-ghost"><i class="fas fa-list-check"></i> QCM de validation</a>
    <a href="<?php echo SITE_URL; ?>/pages/academy/lsfd.php" class="lf-btn lf-btn-ghost"><i class="fas fa-school"></i> Espace Académie LSFD</a>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php';
