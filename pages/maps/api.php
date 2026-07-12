<?php
/**
 * pages/maps/api.php
 * ─────────────────────────────────────────────────────────────────────────
 * API JSON du Système de Cartes MDT — envelope {ok:true,data} / {ok:false,error}.
 * Auth par session (requireLogin) + CSRF sur les mutations + contrôle d'accès
 * par faction (maps_user_can / maps_can_*_map).
 *
 * Actions :
 *   GET  list_maps  (faction)                     — galerie d'une faction
 *   GET  list_tags                                — tags disponibles
 *   GET  get_map    (id | special)                — méta + éléments (bootstrap éditeur)
 *   GET  poll       (id, since)                    — revision + éléments + présence
 *   POST create_map (faction,title,tag_id,bg,vis)  — nouvelle carte
 *   POST delete_map (id)
 *   POST save_element   (map_id,type,data)         — ajout stroke/marker/zone
 *   POST update_element (map_id,element_id,data)
 *   POST delete_element (map_id,element_id)
 *   POST presence   (map_id,nx,ny)
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
requireLogin();
require_once __DIR__ . '/includes/map_bootstrap.php';
require_once __DIR__ . '/includes/map_access.php';
require_once __DIR__ . '/includes/map_assets.php';

$db = Database::getInstance()->getConnection();
mdt_maps_bootstrap($db);

$uid    = maps_user_id();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

/* ─── Helpers ─────────────────────────────────────────────────── */

function maps_get_map(PDO $db, int $id): ?array
{
    $st = $db->prepare("SELECT * FROM mdt_maps WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function maps_get_singleton(PDO $db, string $scope): ?array
{
    $scope = ($scope === 'gang') ? 'gang' : 'commune';
    $st = $db->prepare("SELECT * FROM mdt_maps WHERE scope = ? ORDER BY id ASC LIMIT 1");
    $st->execute([$scope]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Nettoie/valide la donnée d'un élément selon son type. Retourne null si invalide. */
function maps_sanitize_element(string $type, $data): ?array
{
    if (!is_array($data)) {
        return null;
    }
    $hex = static function ($c, $def = '#ef4444') {
        $c = is_string($c) ? trim($c) : '';
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $def;
    };
    $clampPts = static function ($pts, int $max) {
        if (!is_array($pts)) {
            return [];
        }
        $out = [];
        foreach ($pts as $p) {
            if (!is_array($p) || count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
                continue;
            }
            $out[] = [
                round(max(-0.05, min(1.05, (float) $p[0])), 5),
                round(max(-0.05, min(1.05, (float) $p[1])), 5),
            ];
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    };

    if ($type === 'stroke') {
        $tool = in_array(($data['tool'] ?? ''), ['pen', 'marker', 'eraser'], true) ? $data['tool'] : 'pen';
        $pts  = $clampPts($data['points'] ?? [], 6000);
        if (count($pts) < 1) {
            return null;
        }
        return [
            'tool'   => $tool,
            'color'  => $hex($data['color'] ?? '#ef4444'),
            'width'  => max(1, min(64, (int) ($data['width'] ?? 3))),
            'points' => $pts,
        ];
    }
    if ($type === 'marker') {
        if (!isset($data['x'], $data['y']) || !is_numeric($data['x']) || !is_numeric($data['y'])) {
            return null;
        }
        return [
            'x'     => round(max(0, min(1, (float) $data['x'])), 5),
            'y'     => round(max(0, min(1, (float) $data['y'])), 5),
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 120),
            'color' => $hex($data['color'] ?? '#ef4444'),
        ];
    }
    if ($type === 'zone') {
        $pts = $clampPts($data['points'] ?? [], 200);
        if (count($pts) < 3) {
            return null;
        }
        return [
            'points' => $pts,
            'title'  => mb_substr(trim((string) ($data['title'] ?? '')), 0, 120),
            'color'  => $hex($data['color'] ?? '#94a3b8'),
        ];
    }
    return null;
}

/** Représentation JSON d'une ligne d'élément (id + type + champs de data). */
function maps_element_out(array $row): array
{
    $d = json_decode((string) $row['data'], true);
    if (!is_array($d)) {
        $d = [];
    }
    return array_merge(['id' => (int) $row['id'], 'type' => $row['element_type']], $d);
}

/** Éléments d'une carte (ordonnés par id → ordre de dessin). */
function maps_load_elements(PDO $db, int $mapId): array
{
    $st = $db->prepare("SELECT id, element_type, data FROM mdt_map_elements WHERE map_id = ? ORDER BY id ASC");
    $st->execute([$mapId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = maps_element_out($r);
    }
    return $out;
}

function maps_bump_revision(PDO $db, int $mapId): int
{
    $db->prepare("UPDATE mdt_maps SET revision = revision + 1 WHERE id = ?")->execute([$mapId]);
    $st = $db->prepare("SELECT revision FROM mdt_maps WHERE id = ?");
    $st->execute([$mapId]);
    return (int) $st->fetchColumn();
}

/** Présence active (< 20 s), hors soi-même marqué self. */
function maps_load_presence(PDO $db, int $mapId, int $uid): array
{
    try {
        $db->prepare("DELETE FROM mdt_map_presence WHERE map_id = ? AND updated_at < (NOW() - INTERVAL 25 SECOND)")
           ->execute([$mapId]);
        $st = $db->prepare("SELECT user_id, display_name, nx, ny FROM mdt_map_presence
                            WHERE map_id = ? AND updated_at >= (NOW() - INTERVAL 20 SECOND)");
        $st->execute([$mapId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'user_id'      => (int) $r['user_id'],
                'display_name' => $r['display_name'],
                'nx'           => (float) $r['nx'],
                'ny'           => (float) $r['ny'],
                'self'         => ((int) $r['user_id'] === $uid),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

$mutations = ['create_map', 'delete_map', 'save_element', 'update_element', 'delete_element', 'presence'];
if (in_array($action, $mutations, true) && !mdt_map_verify_csrf((string) ($body['csrf'] ?? ''))) {
    maps_json_err('Session expirée, rechargez la carte.', 419);
}

/* ─── Router ──────────────────────────────────────────────────── */
try {
    switch ($action) {

        /* ═══════════ GALERIE FACTION ═══════════ */
        case 'list_maps': {
            $faction = strtoupper(trim((string) ($_GET['faction'] ?? '')));
            if (!in_array($faction, maps_service_faction_keys(), true)) {
                maps_json_err('Faction inconnue.');
            }
            if (!maps_user_can($db, $uid, $faction, 'view')) {
                maps_json_err('Accès refusé.', 403);
            }
            $tagMap = [];
            foreach ($db->query("SELECT id, nom, color FROM mdt_map_tags")->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $tagMap[(int) $t['id']] = $t;
            }
            $st = $db->prepare("SELECT * FROM mdt_maps WHERE scope = 'faction' AND faction_key = ? ORDER BY updated_at DESC");
            $st->execute([$faction]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                if (!maps_can_view_map($db, $uid, $m)) {
                    continue;
                }
                $tag = isset($tagMap[(int) $m['tag_id']]) ? $tagMap[(int) $m['tag_id']] : null;
                $out[] = [
                    'id'            => (int) $m['id'],
                    'title'         => $m['title'],
                    'background'    => $m['background'],
                    'background_label' => ucfirst($m['background']),
                    'thumb'         => maps_background_thumb($m['background']),
                    'visibility'    => $m['visibility'],
                    'tag'           => $tag ? ['nom' => $tag['nom'], 'color' => $tag['color']] : null,
                    'creator_name'  => $m['creator_name'] ?: 'Inconnu',
                    'updated_at'    => $m['updated_at'],
                    'can_edit'      => maps_can_edit_map($db, $uid, $m),
                    'can_delete'    => maps_can_delete_map($db, $uid, $m),
                ];
            }
            maps_json_ok([
                'maps'       => $out,
                'can_create' => maps_user_can($db, $uid, $faction, 'create'),
            ]);
            break;
        }

        /* ═══════════ TAGS ═══════════ */
        case 'list_tags': {
            $rows = $db->query("SELECT id, nom, color FROM mdt_map_tags ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
            maps_json_ok(['tags' => $rows]);
            break;
        }

        /* ═══════════ CRÉATION ═══════════ */
        case 'create_map': {
            $faction = strtoupper(trim((string) ($body['faction'] ?? '')));
            if (!in_array($faction, maps_service_faction_keys(), true)) {
                maps_json_err('Faction inconnue.');
            }
            if (!maps_user_can($db, $uid, $faction, 'create')) {
                maps_json_err('Vous n\'avez pas le droit de créer une carte pour cette faction.', 403);
            }
            $title = mb_substr(trim((string) ($body['title'] ?? '')), 0, 150);
            if ($title === '') {
                $title = 'Carte sans titre';
            }
            $bg  = maps_background_key((string) ($body['background'] ?? 'atlas'));
            $vis = in_array(($body['visibility'] ?? ''), ['draft', 'public', 'private'], true) ? $body['visibility'] : 'draft';
            $tagId = (int) ($body['tag_id'] ?? 0);
            if ($tagId > 0) {
                $chk = $db->prepare("SELECT COUNT(*) FROM mdt_map_tags WHERE id = ?");
                $chk->execute([$tagId]);
                if (!(int) $chk->fetchColumn()) {
                    $tagId = 0;
                }
            }
            $creatorName = function_exists('mdt_session_display_name') ? mdt_session_display_name() : ($_SESSION['user_name'] ?? 'Agent');
            $db->prepare("INSERT INTO mdt_maps (scope, faction_key, title, tag_id, background, visibility, creator_id, creator_name)
                          VALUES ('faction', ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$faction, $title, $tagId ?: null, $bg, $vis, $uid, $creatorName]);
            maps_json_ok(['id' => (int) $db->lastInsertId()]);
            break;
        }

        /* ═══════════ SUPPRESSION ═══════════ */
        case 'delete_map': {
            $id  = (int) ($body['id'] ?? 0);
            $map = $id > 0 ? maps_get_map($db, $id) : null;
            if (!$map) {
                maps_json_err('Carte introuvable.', 404);
            }
            if ($map['scope'] !== 'faction') {
                maps_json_err('Cette carte ne peut pas être supprimée.', 403);
            }
            if (!maps_can_delete_map($db, $uid, $map)) {
                maps_json_err('Accès refusé.', 403);
            }
            $db->prepare("DELETE FROM mdt_map_elements WHERE map_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM mdt_map_presence WHERE map_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM mdt_maps WHERE id = ?")->execute([$id]);
            maps_json_ok();
            break;
        }

        /* ═══════════ CHARGEMENT ÉDITEUR ═══════════ */
        case 'get_map': {
            $special = strtolower(trim((string) ($_GET['special'] ?? '')));
            if ($special === 'commune' || $special === 'gang') {
                $map = maps_get_singleton($db, $special);
            } else {
                $map = maps_get_map($db, (int) ($_GET['id'] ?? 0));
            }
            if (!$map) {
                maps_json_err('Carte introuvable.', 404);
            }
            if (!maps_can_view_map($db, $uid, $map)) {
                maps_json_err('Accès refusé.', 403);
            }
            maps_json_ok([
                'map' => [
                    'id'         => (int) $map['id'],
                    'scope'      => $map['scope'],
                    'title'      => $map['title'],
                    'background' => $map['background'],
                    'revision'   => (int) $map['revision'],
                ],
                'elements' => maps_load_elements($db, (int) $map['id']),
                'can_edit' => maps_can_edit_map($db, $uid, $map),
            ]);
            break;
        }

        /* ═══════════ POLLING TEMPS RÉEL ═══════════ */
        case 'poll': {
            $special = strtolower(trim((string) ($_GET['special'] ?? '')));
            if ($special === 'commune' || $special === 'gang') {
                $map = maps_get_singleton($db, $special);
            } else {
                $map = maps_get_map($db, (int) ($_GET['id'] ?? 0));
            }
            if (!$map) {
                maps_json_err('Carte introuvable.', 404);
            }
            if (!maps_can_view_map($db, $uid, $map)) {
                maps_json_err('Accès refusé.', 403);
            }
            $mapId = (int) $map['id'];
            $rev   = (int) $map['revision'];
            $since = (int) ($_GET['since'] ?? -1);
            $data  = ['revision' => $rev, 'presence' => maps_load_presence($db, $mapId, $uid)];
            if ($since < $rev) {
                $data['elements'] = maps_load_elements($db, $mapId);
            }
            maps_json_ok($data);
            break;
        }

        /* ═══════════ ÉLÉMENTS ═══════════ */
        case 'save_element': {
            [$map, $mapId] = maps_require_editable($db, $uid, $body);
            $type  = (string) ($body['type'] ?? '');
            if (!in_array($type, ['stroke', 'marker', 'zone'], true)) {
                maps_json_err('Type d\'élément invalide.');
            }
            $clean = maps_sanitize_element($type, $body['data'] ?? null);
            if ($clean === null) {
                maps_json_err('Données d\'élément invalides.');
            }
            $db->prepare("INSERT INTO mdt_map_elements (map_id, element_type, data, created_by) VALUES (?, ?, ?, ?)")
               ->execute([$mapId, $type, json_encode($clean, JSON_UNESCAPED_UNICODE), $uid]);
            $newId = (int) $db->lastInsertId();
            $rev   = maps_bump_revision($db, $mapId);
            maps_json_ok(['id' => $newId, 'revision' => $rev]);
            break;
        }

        case 'update_element': {
            [$map, $mapId] = maps_require_editable($db, $uid, $body);
            $eid = (int) ($body['element_id'] ?? 0);
            $st  = $db->prepare("SELECT element_type FROM mdt_map_elements WHERE id = ? AND map_id = ?");
            $st->execute([$eid, $mapId]);
            $type = $st->fetchColumn();
            if (!$type) {
                maps_json_err('Élément introuvable.', 404);
            }
            $clean = maps_sanitize_element((string) $type, $body['data'] ?? null);
            if ($clean === null) {
                maps_json_err('Données d\'élément invalides.');
            }
            $db->prepare("UPDATE mdt_map_elements SET data = ? WHERE id = ? AND map_id = ?")
               ->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $eid, $mapId]);
            $rev = maps_bump_revision($db, $mapId);
            maps_json_ok(['revision' => $rev]);
            break;
        }

        case 'delete_element': {
            [$map, $mapId] = maps_require_editable($db, $uid, $body);
            $eid = (int) ($body['element_id'] ?? 0);
            $db->prepare("DELETE FROM mdt_map_elements WHERE id = ? AND map_id = ?")->execute([$eid, $mapId]);
            $rev = maps_bump_revision($db, $mapId);
            maps_json_ok(['revision' => $rev]);
            break;
        }

        /* ═══════════ PRÉSENCE ═══════════ */
        case 'presence': {
            [$map, $mapId] = maps_require_viewable($db, $uid, $body);
            $nx = max(0, min(1, (float) ($body['nx'] ?? 0.5)));
            $ny = max(0, min(1, (float) ($body['ny'] ?? 0.5)));
            $name = function_exists('mdt_session_display_name') ? mdt_session_display_name() : 'Agent';
            $name = mb_substr((string) $name, 0, 120) ?: 'Agent';
            $db->prepare("INSERT INTO mdt_map_presence (map_id, user_id, display_name, nx, ny)
                          VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), nx = VALUES(nx), ny = VALUES(ny)")
               ->execute([$mapId, $uid, $name, $nx, $ny]);
            maps_json_ok();
            break;
        }

        default:
            maps_json_err('Action inconnue.', 404);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[MDT Maps][api] ' . $e->getMessage());
    maps_json_err('Erreur serveur.', 500);
}

/* ─── Guards partagés (déclarés après usage : hoisting des fonctions PHP) ─── */

/**
 * Résout la carte ciblée (id ou special) et vérifie le droit d'ÉDITION.
 * @return array{0:array,1:int}
 */
function maps_require_editable(PDO $db, int $uid, array $body): array
{
    $special = strtolower(trim((string) ($body['special'] ?? '')));
    if ($special === 'commune' || $special === 'gang') {
        $map = maps_get_singleton($db, $special);
    } else {
        $map = maps_get_map($db, (int) ($body['map_id'] ?? 0));
    }
    if (!$map) {
        maps_json_err('Carte introuvable.', 404);
    }
    if (!maps_can_edit_map($db, $uid, $map)) {
        maps_json_err('Accès refusé (édition).', 403);
    }
    return [$map, (int) $map['id']];
}

/**
 * Résout la carte ciblée (id ou special) et vérifie le droit de VUE.
 * @return array{0:array,1:int}
 */
function maps_require_viewable(PDO $db, int $uid, array $body): array
{
    $special = strtolower(trim((string) ($body['special'] ?? '')));
    if ($special === 'commune' || $special === 'gang') {
        $map = maps_get_singleton($db, $special);
    } else {
        $map = maps_get_map($db, (int) ($body['map_id'] ?? 0));
    }
    if (!$map) {
        maps_json_err('Carte introuvable.', 404);
    }
    if (!maps_can_view_map($db, $uid, $map)) {
        maps_json_err('Accès refusé.', 403);
    }
    return [$map, (int) $map['id']];
}
