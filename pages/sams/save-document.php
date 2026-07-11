<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
// Sauvegarder le document dans la base (à implémenter)
redirect(SITE_URL . '/pages/sams/citoyens.php');
