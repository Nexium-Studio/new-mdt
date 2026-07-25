<?php
/**
 * Dynasty · Site public — Bootstrap
 * ─────────────────────────────────────────────────────────────────────────
 * Init partagé du site public immobilier (calculateur + carte + catalogue).
 *
 * NB : le site public est volontairement NON authentifié — accessible à tout
 *       habitant de Lysoria, citoyens compris. Aucun requireLogin() ici, et
 *       aucun outil d'édition n'est jamais servi depuis ce dossier, quel que
 *       soit le grade de la personne éventuellement connectée.
 */

if (!defined('DY_PUBLIC_INIT')) {
    define('DY_PUBLIC_INIT', true);

    require_once __DIR__ . '/../../../../config/config.php';
    require_once ROOT_PATH . '/includes/dynasty_pricing.php';
    require_once __DIR__ . '/../includes/zones_bootstrap.php';
    require_once ROOT_PATH . '/pages/maps/includes/map_assets.php';

    if (!function_exists('dy_db')) {
        function dy_db(): PDO { return Database::getInstance()->getConnection(); }
    }

    if (!function_exists('dy_pub_url')) {
        function dy_pub_url(string $rel = ''): string {
            return SITE_URL . '/pages/companies/dynasty/public/' . ltrim($rel, '/');
        }
    }

    if (!function_exists('dy_has_column')) {
        /** La colonne existe-t-elle ? (cache par requête, dégradation gracieuse) */
        function dy_has_column(string $table, string $column): bool {
            static $cache = [];
            $key = "$table.$column";
            if (isset($cache[$key])) return $cache[$key];
            try {
                $st = dy_db()->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $st->execute([$table, $column]);
                return $cache[$key] = ((int) $st->fetchColumn() > 0);
            } catch (Throwable $e) {
                return $cache[$key] = false;
            }
        }
    }

    if (!function_exists('dy_esc')) {
        function dy_esc(?string $s): string {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('dy_pub_logo')) {
        /** Logo Dynasty (admin_panel_config.custom_logo_url), ou null. */
        function dy_pub_logo(): ?string {
            require_once ROOT_PATH . '/includes/faction_logo.php';
            return faction_logo_url(dy_db(), 'DYNASTY');
        }
    }

    if (!function_exists('dy_pub_montant')) {
        /** Formatage d'un montant, miroir de dynasty_pricing_format(). */
        function dy_pub_montant(float $m, string $devise = '$'): string {
            return dynasty_pricing_format($m, $devise);
        }
    }
}
