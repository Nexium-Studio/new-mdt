<?php
require_once dirname(__DIR__, 4) . '/pages/panel-base/render_header.php';
// Surcharge de thème LSFD (lsfd_settings) + styles de page partagés.
// Émis en tout début de <body> → valide et surcharge le thème auto du <head>.
include __DIR__ . '/theme.php';
include __DIR__ . '/ui.php';
