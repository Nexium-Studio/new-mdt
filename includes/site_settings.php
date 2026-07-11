<?php
declare(strict_types=1);

/**
 * Helpers de configuration dynamique du site via admin_settings.
 */

function mdt_admin_settings_fetch(PDO $db, array $keys = []): array
{
    $settings = [];
    if ($keys === []) {
        $rows = $db->query("SELECT setting_key, setting_value FROM admin_settings")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $st = $db->prepare("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key IN ($placeholders)");
        $st->execute(array_values($keys));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as $row) {
        $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
    }
    return $settings;
}

function mdt_admin_settings_upsert(PDO $db, string $key, string $value): void
{
    $st = $db->prepare(
        "INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $st->execute([$key, $value]);
}

function mdt_validate_hex_color(string $color, string $fallback): string
{
    $c = trim($color);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtoupper($c) : $fallback;
}

function mdt_validate_theme_mode(string $mode, string $fallback = 'dark'): string
{
    $allowed = ['dark', 'light', 'auto'];
    return in_array($mode, $allowed, true) ? $mode : $fallback;
}

function mdt_validate_site_url(string $value, string $fallback = ''): string
{
    $url = trim($value);
    if ($url === '') {
        return $fallback;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return $fallback;
    }
    $scheme = (string) parse_url($url, PHP_URL_SCHEME);
    if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
        return $fallback;
    }
    return rtrim($url, '/');
}

function mdt_site_branding(PDO $db): array
{
    $defaults = [
        'site_name' => defined('SITE_NAME') ? (string) SITE_NAME : 'MDT',
        'site_tagline' => 'Plateforme MDT nouvelle generation',
        'site_url' => defined('SITE_URL') ? (string) SITE_URL : '',
        'theme_mode' => 'dark',
        'primary_color' => '#3B82F6',
        'secondary_color' => '#6366F1',
        'logo_url' => '',
        'favicon_url' => '',
    ];

    $map = [
        'site_name',
        'site_tagline',
        'site_url',
        'theme_mode',
        'primary_color',
        'secondary_color',
        'logo_url',
        'favicon_url',
    ];
    $rows = mdt_admin_settings_fetch($db, $map);

    return [
        'site_name' => trim($rows['site_name'] ?? '') !== '' ? trim((string) $rows['site_name']) : $defaults['site_name'],
        'site_tagline' => trim($rows['site_tagline'] ?? '') !== '' ? trim((string) $rows['site_tagline']) : $defaults['site_tagline'],
        'site_url' => mdt_validate_site_url((string) ($rows['site_url'] ?? ''), $defaults['site_url']),
        'theme_mode' => mdt_validate_theme_mode((string) ($rows['theme_mode'] ?? ''), $defaults['theme_mode']),
        'primary_color' => mdt_validate_hex_color((string) ($rows['primary_color'] ?? ''), $defaults['primary_color']),
        'secondary_color' => mdt_validate_hex_color((string) ($rows['secondary_color'] ?? ''), $defaults['secondary_color']),
        'logo_url' => mdt_validate_site_url((string) ($rows['logo_url'] ?? ''), ''),
        'favicon_url' => mdt_validate_site_url((string) ($rows['favicon_url'] ?? ''), ''),
    ];
}
