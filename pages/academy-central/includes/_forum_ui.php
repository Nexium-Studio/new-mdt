<?php
/**
 * _forum_ui.php — fonctions et CSS partagés entre les vues forum.
 * Inclus une seule fois par les sous-vues via require_once.
 */
if (defined('ACADEMY_FORUM_UI_LOADED')) return;
define('ACADEMY_FORUM_UI_LOADED', true);

/**
 * Génère un avatar circulaire pour un user (utilise initiale si pas d'avatar).
 */
function ac_forum_avatar(?string $username, ?string $avatar = null, int $size = 36): string {
    $sz = (int)$size;
    $init = $username ? strtoupper(mb_substr($username, 0, 1)) : '?';
    if ($avatar) {
        $av = htmlspecialchars($avatar);
        return "<div class=\"f-ava\" style=\"width:{$sz}px;height:{$sz}px\"><img src=\"{$av}\" alt=\"\"></div>";
    }
    return "<div class=\"f-ava\" style=\"width:{$sz}px;height:{$sz}px\">" . htmlspecialchars($init) . "</div>";
}

/**
 * Format relatif court : "il y a 3h", "il y a 2j", "le 12/03 à 14:25"
 */
function ac_forum_time_ago(?string $datetime): string {
    if (!$datetime) return '—';
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60)         return 'à l\'instant';
    if ($diff < 3600)       return 'il y a ' . (int)floor($diff / 60) . ' min';
    if ($diff < 86400)      return 'il y a ' . (int)floor($diff / 3600) . ' h';
    if ($diff < 604800)     return 'il y a ' . (int)floor($diff / 86400) . ' j';
    return 'le ' . date('d/m H:i', $ts);
}

/**
 * Renvoie une couleur de tag avec fond rgba (low alpha) et texte vif.
 */
function ac_forum_tag_chip(array $tag, bool $small = true): string {
    $c = htmlspecialchars($tag['color'] ?: '#8b5cf6');
    [$r,$g,$b] = sscanf($c, "#%02x%02x%02x");
    $bg = "rgba($r,$g,$b,.12)";
    $br = "rgba($r,$g,$b,.30)";
    $cls = $small ? 'ftag ftag-sm' : 'ftag';
    return '<span class="' . $cls . '" style="background:' . $bg . ';border-color:' . $br . ';color:' . $c . '">' . htmlspecialchars($tag['label']) . '</span>';
}
