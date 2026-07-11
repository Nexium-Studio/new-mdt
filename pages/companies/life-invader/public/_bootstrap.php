<?php
/**
 * Life Invader · Public Site Bootstrap
 * Shared init for the public-facing newspaper site.
 *
 * NB : Le site public est volontairement non-authentifié — il s'agit
 *       d'un site éditorial RP accessible à tous les habitants de Lysoria.
 */

if (!defined('LI_PUBLIC_INIT')) {
    define('LI_PUBLIC_INIT', true);

    require_once __DIR__ . '/../../../../config/config.php';

    if (!function_exists('li_db')) {
        function li_db(): PDO { return Database::getInstance()->getConnection(); }
    }

    if (!function_exists('li_pub_url')) {
        function li_pub_url(string $rel = ''): string {
            return SITE_URL . '/pages/companies/life-invader/public/' . ltrim($rel, '/');
        }
    }

    if (!function_exists('li_plain')) {
        /**
         * Convertit du HTML ou du texte avec entités vers du texte brut propre.
         * Gère &nbsp; et autres entités HTML qui auraient été enregistrées telles quelles.
         */
        function li_plain(?string $text): string {
            if (!$text) return '';
            // Strip HTML d'abord (au cas où l'entrée contient des balises)
            $t = strip_tags((string)$text);
            // Décoder les entités HTML (y compris &nbsp;, &amp;, &eacute;, etc.)
            $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Remplacer les espaces insécables Unicode (U+00A0) par un espace normal
            $t = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $t);
            // Normaliser les espaces multiples
            $t = preg_replace('/\s+/u', ' ', $t);
            return trim($t);
        }
    }

    if (!function_exists('li_excerpt')) {
        function li_excerpt(?string $html, int $max = 180): string {
            $txt = li_plain($html);
            if ($txt === '') return '';
            if (mb_strlen($txt) <= $max) return $txt;
            return mb_substr($txt, 0, $max - 1) . '…';
        }
    }

    if (!function_exists('li_image_for')) {
        function li_image_for(array $row): ?string {
            return $row['image_principale'] ?? null;
        }
    }

    if (!function_exists('li_pub_sanitize_html')) {
        function li_pub_sanitize_html(?string $html): string {
            if (!$html) return '';
            // Corrige les éventuelles double-encodages (&amp;nbsp; → &nbsp;)
            $html = preg_replace('/&amp;(nbsp|amp|lt|gt|quot|#\d+|#x[0-9a-fA-F]+);/', '&$1;', $html);
            $html = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $html);
            $html = preg_replace('#<\s*iframe\b[^>]*>.*?<\s*/\s*iframe\s*>#is', '', $html);
            $html = preg_replace('#<\s*(script|iframe|object|embed|form|input|button|style)\b[^>]*/?>#i', '', $html);
            $html = preg_replace('#\s+on[a-z]+\s*=\s*"[^"]*"#i', '', $html);
            $html = preg_replace("#\s+on[a-z]+\s*=\s*'[^']*'#i", '', $html);
            $html = preg_replace('#(href|src)\s*=\s*"javascript:[^"]*"#i', '$1="#"', $html);
            $html = preg_replace("#(href|src)\s*=\s*'javascript:[^']*'#i", '$1="#"', $html);
            return $html;
        }
    }

    if (!function_exists('li_pub_author_name')) {
        function li_pub_author_name(array $row): string {
            $fn = $row['author_firstname'] ?? null;
            $ln = $row['author_lastname']  ?? null;
            $un = (string)($row['author_username'] ?? $row['auteur'] ?? '');
            if (function_exists('mdt_rp_display_name')) {
                return mdt_rp_display_name($fn, $ln, $un);
            }
            $rp = trim(trim((string)$fn) . ' ' . trim((string)$ln));
            return $rp !== '' ? $rp : $un;
        }
    }

    if (!function_exists('li_time_ago')) {
        function li_time_ago(?string $datetime): string {
            if (!$datetime) return '';
            $t = strtotime($datetime); if (!$t) return '';
            $diff = time() - $t;
            if ($diff < 60)    return "à l'instant";
            if ($diff < 3600)  return 'il y a ' . floor($diff / 60) . ' min';
            if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . ' h';
            if ($diff < 604800){ $d=floor($diff/86400); return 'il y a ' . $d . ' j'; }
            return date('d/m/Y', $t);
        }
    }

    if (!function_exists('li_track_view')) {
        function li_track_view(int $articleId): void {
            try {
                $db = li_db();
                $db->prepare("UPDATE li_articles SET nb_vues = nb_vues + 1 WHERE id=? AND statut='publie'")->execute([$articleId]);
            } catch (Throwable $e) { /* fail-silent */ }
        }
    }

    if (!function_exists('li_get_categories')) {
        function li_get_categories(): array {
            try {
                $rows = li_db()->query("SELECT id, nom, slug, couleur, icone FROM li_categories WHERE actif=1 ORDER BY ordre ASC, nom ASC")->fetchAll(PDO::FETCH_ASSOC);
                return $rows ?: [];
            } catch (Throwable $e) { return []; }
        }
    }

    if (!function_exists('li_has_column')) {
        function li_has_column(string $table, string $column): bool {
            static $cache = [];
            $key = "$table.$column";
            if (isset($cache[$key])) return $cache[$key];
            try {
                $st = li_db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
                $st->execute([$table, $column]);
                return $cache[$key] = ($st->fetchColumn() > 0);
            } catch (Throwable $e) { return $cache[$key] = false; }
        }
    }
}
