<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
// report-create.php merged into reports.php
header('Location: ' . SITE_URL . '/pages/lspd/reports.php?new=1');
exit;