<?php
// pages/staff/content/panels.php
// ─────────────────────────────────────────────────────────────────────
// Remplacée par le nouveau Constructeur de dashboard (bouton « Custom »
// → sidebar → « Gérer les panels »). Ancienne version conservée dans
// panels.php.bak pour rollback. Décision client : remplacer l'ancienne page.
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}
// Dev/Admin : ouvre directement le pane de gestion des panels.
// Autres rôles staff : atterrissent sur le dashboard (gestion réservée Dev/Admin).
redirect(SITE_URL . '/pages/dashboard.php?builder=panels');
