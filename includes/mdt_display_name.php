<?php
/**
 * Nom RP (profiles) vs pseudo — affichage cohérent sur le MDT.
 */

declare(strict_types=1);

if (!function_exists('mdt_rp_display_name')) {
    /**
     * Nom affiché : prénom + nom RP si au moins une partie utile, sinon pseudo.
     */
    function mdt_rp_display_name(?string $firstname, ?string $lastname, string $usernameFallback): string
    {
        $u = trim($usernameFallback);
        $fn = trim((string) ($firstname ?? ''));
        $ln = trim((string) ($lastname ?? ''));
        foreach (['—', '-', '–'] as $dash) {
            if ($fn === $dash) {
                $fn = '';
            }
            if ($ln === $dash) {
                $ln = '';
            }
        }
        $parts = array_filter([$fn, $ln], static fn ($p) => $p !== '');
        if ($parts === []) {
            return $u !== '' ? $u : '—';
        }
        return trim(implode(' ', $parts));
    }
}

if (!function_exists('mdt_rp_initials')) {
    function mdt_rp_initials(?string $firstname, ?string $lastname, string $usernameFallback): string
    {
        $display = mdt_rp_display_name($firstname, $lastname, $usernameFallback);
        $display = trim($display);
        if ($display === '' || $display === '—') {
            return 'U';
        }
        return strtoupper(mb_substr($display, 0, 1));
    }
}

if (!function_exists('mdt_session_refresh_display_name')) {
    /**
     * Met à jour $_SESSION['user_display_name'] depuis users + profiles.
     */
    function mdt_session_refresh_display_name(): void
    {
        if (!function_exists('isLoggedIn') || !isLoggedIn()) {
            return;
        }
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare('
                SELECT u.username, p.firstname AS profile_firstname, p.lastname AS profile_lastname
                FROM users u
                LEFT JOIN profiles p ON p.id = u.profile_id
                WHERE u.id = ?
                LIMIT 1
            ');
            $st->execute([$uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $un = (string) ($row['username'] ?? '');
            $_SESSION['user_display_name'] = mdt_rp_display_name(
                $row['profile_firstname'] ?? null,
                $row['profile_lastname'] ?? null,
                $un
            );
            $_SESSION['user_rp_firstname'] = (string) ($row['profile_firstname'] ?? '');
            $_SESSION['user_rp_lastname'] = (string) ($row['profile_lastname'] ?? '');
        } catch (Throwable $e) {
            $un = (string) ($_SESSION['user_username'] ?? '');
            $_SESSION['user_display_name'] = mdt_rp_display_name(null, null, $un);
        }
    }
}

if (!function_exists('mdt_session_display_name')) {
    function mdt_session_display_name(): string
    {
        $d = trim((string) ($_SESSION['user_display_name'] ?? ''));
        if ($d !== '') {
            return $d;
        }
        return (string) ($_SESSION['user_username'] ?? '');
    }
}

if (!function_exists('mdt_session_display_initial')) {
    function mdt_session_display_initial(): string
    {
        $n = mdt_session_display_name();
        return $n !== '' ? strtoupper(mb_substr($n, 0, 1)) : 'U';
    }
}
