<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$pageTitle = 'Dashboard LSSD';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2>Panel LSSD</h2>
    </div>
    <div style="padding: 2rem;">
        <p style="color: var(--text-secondary); font-size: 1.1rem; line-height: 1.8; margin-bottom: 2rem;">
            Bienvenue sur le panel LSSD. Ce panel est en cours de développement.
        </p>
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="btn btn-primary">
            Retour à l'accueil
        </a>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
