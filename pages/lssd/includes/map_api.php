<?php
/**
 * Accès panel LSSD (cartes tactiques) — réutilisé par les endpoints AJAX.
 */
declare(strict_types=1);

function lssd_map_panel_access_ok(): bool
{
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return false;
    }
    $f = $_SESSION['user_faction'] ?? '';
    if (in_array($f, ['LSPD', 'LSSD', 'DOJ'], true)) {
        return true;
    }
    return hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal']);
}

/**
 * Grades LSSD autorisés à modifier n'importe quel plan (hors créateur / staff).
 *
 * @return array<int, string>
 */
function lssd_map_editor_grades(): array
{
    return [
        'Sergeant',
        'Sergeant',
        'Lieutenant',
        'Lieutenant',
        'Captain',
        'Captain',
        'Area Commander',
        'Division Chief',
        'Undersheriff',
        'Sheriff',
    ];
}

/**
 * Un utilisateur peut-il modifier le plan (carte) dont l'auteur est $authorId ?
 *
 * Accès autorisé :
 *  - au créateur de la carte,
 *  - aux administrateurs / développeurs / staff (rôles globaux),
 *  - aux membres LSSD dont le grade est dans {@see lssd_map_editor_grades()}.
 */
function lssd_map_user_can_edit(int $authorId): bool
{
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return false;
    }

    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid > 0 && $authorId === $uid) {
        return true;
    }

    if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
        return true;
    }

    $faction = (string) ($_SESSION['user_faction'] ?? '');
    if ($faction === 'LSSD') {
        $grade = trim((string) ($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? ''));
        if ($grade !== '' && in_array($grade, lssd_map_editor_grades(), true)) {
            return true;
        }
    }

    return false;
}

function lssd_map_json_out(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function lssd_map_ensure_csrf(): string
{
    if (empty($_SESSION['lssd_map_csrf']) || !is_string($_SESSION['lssd_map_csrf'])) {
        $_SESSION['lssd_map_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['lssd_map_csrf'];
}

function lssd_map_verify_csrf(?string $t): bool
{
    $s = $_SESSION['lssd_map_csrf'] ?? '';
    return is_string($t) && $s !== '' && hash_equals($s, $t);
}

/**
 * JSON sûr pour inclusion dans une balise &lt;script&gt; (évite page blanche si encodage échoue).
 */
function lssd_map_json_for_script($data, string $fallback = 'null'): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $j = json_encode($data, $flags);
    if ($j === false) {
        return $fallback;
    }
    return $j;
}

/**
 * Viewport utilisable par le moteur carte (scale/translate + dimensions image).
 * Ignore les anciens JSON Leaflet ou les valeurs incomplètes.
 *
 * @param array<string, mixed>|null $vp
 * @return array<string, float|int>|null
 */
function lssd_map_viewport_for_engine(?array $vp): ?array
{
    if ($vp === null) {
        return null;
    }
    if (isset($vp['center']) || isset($vp['zoom'])) {
        if (!isset($vp['scale'], $vp['translateX'], $vp['translateY'])) {
            return null;
        }
    }
    foreach (['scale', 'translateX', 'translateY', 'mapW', 'mapH'] as $k) {
        if (!array_key_exists($k, $vp)) {
            return null;
        }
        if (!is_numeric($vp[$k])) {
            return null;
        }
        $f = (float) $vp[$k];
        if (!is_finite($f)) {
            return null;
        }
    }
    return $vp;
}
