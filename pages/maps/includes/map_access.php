<?php
/**
 * pages/maps/includes/map_access.php
 * ─────────────────────────────────────────────────────────────────────────
 * Contrôle d'accès, CSRF et helpers JSON du Système de Cartes MDT.
 *
 * Réutilise l'architecture MDT (sessions, hasRole, factions, logos) sans
 * dépendre du module carte LSPD.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/mdt_panel_catalog.php';

if (!function_exists('mdt_map_csrf')) {

    /* ══════════════════════════ CSRF ══════════════════════════ */

    function mdt_map_csrf(): string
    {
        if (empty($_SESSION['mdt_map_csrf']) || !is_string($_SESSION['mdt_map_csrf'])) {
            $_SESSION['mdt_map_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['mdt_map_csrf'];
    }

    function mdt_map_verify_csrf(?string $token): bool
    {
        $s = $_SESSION['mdt_map_csrf'] ?? '';
        return is_string($token) && $s !== '' && hash_equals($s, $token);
    }

    /* ══════════════════════════ JSON ══════════════════════════ */

    function maps_json_out(int $code, array $payload): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @param mixed $data */
    function maps_json_ok($data = []): void
    {
        maps_json_out(200, ['ok' => true, 'data' => $data]);
    }

    function maps_json_err(string $msg, int $code = 400): void
    {
        maps_json_out($code, ['ok' => false, 'error' => $msg]);
    }

    /* ═══════════════════════ Session helpers ═══════════════════════ */

    function maps_user_id(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    function maps_user_faction(): string
    {
        $f = trim((string) ($_SESSION['user_faction'] ?? ''));
        return ($f === '' || strtoupper($f) === 'NONE') ? '' : $f;
    }

    /** Staff global : voit et gère tout. */
    function maps_is_staff(): bool
    {
        return function_exists('hasRole')
            && hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper']);
    }

    /* ═══════════════════════ Métadonnées factions ═══════════════════════ */

    /**
     * Les 9 factions service public gérées par le système (ordre du cahier des charges).
     * @return array<int,string>
     */
    function maps_service_faction_keys(): array
    {
        return ['LSPD', 'GOUV', 'USSS', 'MARSHALS', 'LSFD', 'GRUPP6', 'LSSD', 'SAMS', 'DOJ'];
    }

    /**
     * Métadonnées d'affichage (nom, couleur, icône FA, logo URL) pour chaque faction gérée.
     * Base = catalogue MDT + overrides admin_panel_config (custom_name/color/logo).
     * @return array<string, array{key:string,name:string,color:string,icon:string,logo:string}>
     */
    function maps_faction_meta(PDO $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $wanted = maps_service_faction_keys();

        // Repli si le catalogue est indisponible.
        $fallback = [
            'LSPD'     => ['LSPD', '#2563eb', 'shield-halved'],
            'GOUV'     => ['Gouvernement', '#1d4ed8', 'landmark'],
            'USSS'     => ['USSS', '#334155', 'id-card-clip'],
            'MARSHALS' => ['Marshals', '#475569', 'shield-halved'],
            'LSFD'     => ['LSFD', '#dc2626', 'fire-flame-curved'],
            'GRUPP6'   => ['Grupp 6', '#64748b', 'warehouse'],
            'LSSD'     => ['LSSD', '#10b981', 'star'],
            'SAMS'     => ['SAMS', '#f43f5e', 'kit-medical'],
            'DOJ'      => ['DOJ', '#ef4444', 'scale-balanced'],
        ];

        $meta = [];
        foreach ($wanted as $k) {
            $meta[$k] = [
                'key'   => $k,
                'name'  => $fallback[$k][0] ?? $k,
                'color' => $fallback[$k][1] ?? '#3b82f6',
                'icon'  => $fallback[$k][2] ?? 'shield',
                'logo'  => '',
            ];
        }

        // Base depuis le catalogue MDT (nom/couleur/icône officiels).
        try {
            if (function_exists('mdt_panel_catalog_categories')) {
                foreach (mdt_panel_catalog_categories($db) as $panels) {
                    if (!is_array($panels)) {
                        continue;
                    }
                    foreach ($panels as $p) {
                        $fk = $p['faction'] ?? '';
                        if ($fk !== '' && isset($meta[$fk])) {
                            $meta[$fk]['name']  = $p['name']  ?? $meta[$fk]['name'];
                            $meta[$fk]['color'] = $p['color'] ?? $meta[$fk]['color'];
                            $meta[$fk]['icon']  = $p['icon']  ?? $meta[$fk]['icon'];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
        }

        // Overrides admin_panel_config (nom / couleur / logo personnalisés du MDT).
        try {
            $ph  = implode(',', array_fill(0, count($wanted), '?'));
            $st  = $db->prepare("SELECT panel_key, custom_name, custom_color, custom_logo_url
                                 FROM admin_panel_config WHERE panel_key IN ($ph)");
            $st->execute($wanted);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = $r['panel_key'];
                if (!isset($meta[$k])) {
                    continue;
                }
                if (!empty($r['custom_name']))  { $meta[$k]['name']  = $r['custom_name']; }
                if (!empty($r['custom_color'])) { $meta[$k]['color'] = $r['custom_color']; }
                $logo = trim((string) ($r['custom_logo_url'] ?? ''));
                if ($logo !== '' && preg_match('#^https?://#i', $logo)) {
                    $meta[$k]['logo'] = $logo;
                }
            }
        } catch (Throwable $e) {
        }

        $cache = $meta;
        return $cache;
    }

    /**
     * Clés des factions dont la carte est activée (apparition navbar).
     * @return array<int,string>
     */
    function maps_active_faction_keys(PDO $db): array
    {
        try {
            $rows = $db->query("SELECT faction_key FROM mdt_map_factions WHERE is_active = 1")
                       ->fetchAll(PDO::FETCH_COLUMN);
            return array_values(array_intersect(maps_service_faction_keys(), $rows ?: []));
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Droits accordés par une faction propriétaire aux autres factions.
     * @return array<string, array{can_view:int,can_edit:int,can_create:int,can_delete:int}>
     */
    function maps_permissions_for(PDO $db, string $ownerFaction): array
    {
        static $cache = [];
        if (array_key_exists($ownerFaction, $cache)) {
            return $cache[$ownerFaction];
        }
        $out = [];
        try {
            $st = $db->prepare("SELECT faction, can_view, can_edit, can_create, can_delete
                                FROM mdt_map_permissions WHERE owner_faction = ?");
            $st->execute([$ownerFaction]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['faction']] = [
                    'can_view'   => (int) $r['can_view'],
                    'can_edit'   => (int) $r['can_edit'],
                    'can_create' => (int) $r['can_create'],
                    'can_delete' => (int) $r['can_delete'],
                ];
            }
        } catch (Throwable $e) {
        }
        $cache[$ownerFaction] = $out;
        return $out;
    }

    /* ═══════════════════════ Décisions d'accès ═══════════════════════ */

    /**
     * L'utilisateur $uid peut-il $action (view|edit|create|delete) sur les cartes
     * de la faction propriétaire $ownerFaction ?
     */
    function maps_user_can(PDO $db, int $uid, string $ownerFaction, string $action): bool
    {
        $action = in_array($action, ['view', 'edit', 'create', 'delete'], true) ? $action : 'view';

        if (maps_is_staff()) {
            return true;
        }
        if ($action === 'view' && function_exists('is_demo_mode') && is_demo_mode()) {
            return true;
        }
        $userFaction = maps_user_faction();
        if ($userFaction !== '' && $userFaction === $ownerFaction) {
            return true; // la faction propriétaire dispose de tous les droits sur ses cartes
        }
        $perms = maps_permissions_for($db, $ownerFaction);
        $g = $perms[$userFaction] ?? null;
        if ($g === null) {
            return false;
        }
        return !empty($g['can_' . $action]);
    }

    /** Vue d'une carte donnée (superpose la visibilité draft/public/private). */
    function maps_can_view_map(PDO $db, int $uid, array $map): bool
    {
        $scope = $map['scope'] ?? 'faction';
        if ($scope === 'commune' || $scope === 'gang') {
            return function_exists('isLoggedIn') && isLoggedIn();
        }
        if (maps_is_staff()) {
            return true;
        }
        $vis = $map['visibility'] ?? 'draft';
        if ($vis === 'draft' || $vis === 'private') {
            return $uid > 0 && (int) ($map['creator_id'] ?? 0) === $uid;
        }
        // public → droit de vue sur la faction propriétaire
        return maps_user_can($db, $uid, (string) ($map['faction_key'] ?? ''), 'view');
    }

    /** Édition d'une carte donnée. */
    function maps_can_edit_map(PDO $db, int $uid, array $map): bool
    {
        $scope = $map['scope'] ?? 'faction';
        if ($scope === 'commune' || $scope === 'gang') {
            return function_exists('isLoggedIn') && isLoggedIn();
        }
        if (maps_is_staff()) {
            return true;
        }
        if ($uid > 0 && (int) ($map['creator_id'] ?? 0) === $uid) {
            return true; // le créateur édite toujours ses cartes
        }
        $vis = $map['visibility'] ?? 'draft';
        if ($vis === 'draft' || $vis === 'private') {
            return false; // brouillon / privé : créateur uniquement
        }
        return maps_user_can($db, $uid, (string) ($map['faction_key'] ?? ''), 'edit');
    }

    /** Suppression d'une carte donnée. */
    function maps_can_delete_map(PDO $db, int $uid, array $map): bool
    {
        $scope = $map['scope'] ?? 'faction';
        if ($scope === 'commune' || $scope === 'gang') {
            return maps_is_staff(); // les singletons ne se suppriment pas hors staff
        }
        if (maps_is_staff()) {
            return true;
        }
        if ($uid > 0 && (int) ($map['creator_id'] ?? 0) === $uid) {
            return true;
        }
        $vis = $map['visibility'] ?? 'draft';
        if ($vis === 'draft' || $vis === 'private') {
            return false;
        }
        return maps_user_can($db, $uid, (string) ($map['faction_key'] ?? ''), 'delete');
    }

    /**
     * Factions à afficher dans la navbar du panel (activées + vue autorisée).
     * @return array<string, array{key:string,name:string,color:string,icon:string,logo:string}>
     */
    function maps_navbar_factions(PDO $db, int $uid): array
    {
        $active = maps_active_faction_keys($db);
        $meta   = maps_faction_meta($db);
        $out    = [];
        foreach ($meta as $key => $m) {
            if (!in_array($key, $active, true)) {
                continue;
            }
            if (!maps_user_can($db, $uid, $key, 'view')) {
                continue;
            }
            $out[$key] = $m;
        }
        return $out;
    }
}
