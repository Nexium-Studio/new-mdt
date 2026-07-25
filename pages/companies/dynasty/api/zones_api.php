<?php
/**
 * pages/companies/dynasty/api/zones_api.php
 * ─────────────────────────────────────────────────────────────────────────
 * API JSON du module « Zones & Tarification » de Dynasty.
 * Enveloppe {ok:true,data} / {ok:false,error} — contrat strictement identique
 * à pages/maps/api.php pour les actions de carte, afin que le moteur partagé
 * (assets/js/maps/map-engine.js) puisse pointer ici sans adaptation.
 *
 * Le fichier vit dans api/ et non à la racine du panel : faction_acl_scan_pages()
 * ne parcourt que *.php à la racine du dossier de faction, un endpoint posé là
 * remonterait dans roles.php comme une fausse page de permissions.
 *
 * ── Actions carte (contrat map-engine.js) ────────────────────────────────
 *   POST save_element   (type='zone', data)   → zone.create   → {id, revision}
 *   POST update_element (element_id, data)    → zone.edit     → {revision}
 *   POST delete_element (element_id)          → zone.delete   → {revision}
 *
 * ── Actions module ───────────────────────────────────────────────────────
 *   GET  list                                  → zone.view
 *   POST pricing_save_category / _delete_category
 *   POST pricing_save_option   / _delete_option
 *   POST pricing_save_garage   / _delete_garage
 *   POST pricing_save_config
 *
 * Chaque action vérifie sa permission côté serveur avec faction_acl_can().
 * Masquer un bouton dans l'interface ne protège rien : c'est ici que ça se joue.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/config/config.php';

// Session absente : on répond en JSON. requireLogin() redirigerait vers
// login.php, et le client recevrait une page HTML en guise de réponse d'API.
if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => 'Session expirée, reconnectez-vous.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once ROOT_PATH . '/includes/faction_acl.php';
require_once ROOT_PATH . '/includes/dynasty_pricing.php';
require_once dirname(__DIR__) . '/includes/zones_bootstrap.php';

const DY_FACTION = 'DYNASTY';

/* ─── Sortie JSON ─────────────────────────────────────────────── */

function dy_json_out(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function dy_json_ok($data = []): void  { dy_json_out(200, ['ok' => true,  'data'  => $data]); }
function dy_json_err(string $m, int $c = 400, array $extra = []): void
{
    dy_json_out($c, array_merge(['ok' => false, 'error' => $m], $extra));
}

$db  = Database::getInstance()->getConnection();
$uid = (int) ($_SESSION['user_id'] ?? 0);

/* ─── Garde de faction : même règle que les pages du panel ─── */
if (($_SESSION['user_faction'] ?? '') !== DY_FACTION
    && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])
    && !is_demo_mode()) {
    dy_json_err('Accès refusé.', 403);
}

dynasty_zones_bootstrap($db);

/** Exige une permission granulaire — toujours en JSON, jamais une page HTML. */
function dy_require(string $perm): void
{
    global $db, $uid;
    if (!faction_acl_can($db, $uid, DY_FACTION, $perm)) {
        dy_json_err("Permission requise : {$perm}", 403);
    }
}

/* ─── Entrée ──────────────────────────────────────────────────── */

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$body   = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

/* ─── Helpers de validation ───────────────────────────────────── */

/**
 * Nettoyage d'un champ texte libre.
 *
 * NB : on n'utilise volontairement PAS sanitize() (config.php), qui applique
 * htmlspecialchars() à l'écriture. Les libellés de zone repartent ensuite dans
 * htmlspecialchars() à l'affichage et dans json_encode() côté site public :
 * un double encodage afficherait « Vinewood &amp;amp; Co ». On enlève donc les
 * balises et on borne la longueur ici, l'échappement se fait à la sortie.
 */
function dy_text($v, int $max): string
{
    return mb_substr(trim(strip_tags((string) $v)), 0, $max);
}

/** Couleur hexadécimale à 6 chiffres, ou $def (null autorisé). */
function dy_hex($c, ?string $def = null): ?string
{
    $c = is_string($c) ? trim($c) : '';
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $def;
}

function dy_dec($v, float $min, float $max, float $def = 0.0): float
{
    if (!is_numeric($v)) {
        return $def;
    }
    return max($min, min($max, (float) $v));
}

/** Clé technique : minuscules, [a-z0-9_], jamais vide. */
function dy_cle($v, string $repli): string
{
    $k = strtolower(trim((string) $v));
    $k = preg_replace('/[^a-z0-9_]+/', '_', $k) ?? '';
    $k = trim($k, '_');
    return $k !== '' ? mb_substr($k, 0, 50) : $repli;
}

/**
 * Valide et normalise le tracé d'une zone.
 * Reprend les règles de maps_sanitize_element() : paires numériques, bornes
 * [-0.05, 1.05] (le moteur autorise ce léger débord), arrondi à 5 décimales.
 * Plafond à 500 sommets : un payload géant ferait tomber le rendu public.
 *
 * @return array|null null si le polygone est inexploitable.
 */
function dy_points($pts): ?array
{
    if (!is_array($pts)) {
        return null;
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
        if (count($out) >= 500) {
            break;
        }
    }
    return count($out) >= 3 ? $out : null;
}

/** Catégorie existante, ou null. */
function dy_categorie_id(PDO $db, $id): ?int
{
    $id = (int) $id;
    if ($id <= 0) {
        return null;
    }
    $st = $db->prepare("SELECT id FROM dynasty_zone_categories WHERE id = ?");
    $st->execute([$id]);
    return $st->fetchColumn() ? $id : null;
}

/* ─── CSRF sur toutes les mutations ───────────────────────────── */

$lectures = ['list'];
if (!in_array($action, $lectures, true)
    && !dynasty_zones_verify_csrf((string) ($body['csrf'] ?? $_POST['csrf'] ?? ''))) {
    dy_json_err('Session expirée, rechargez la page.', 419);
}

/* ─── Routeur ─────────────────────────────────────────────────── */

try {
    switch ($action) {

        /* ═══════════ LECTURE ═══════════ */
        case 'list': {
            dy_require('zone.view');
            dynasty_pricing_flush_cache();
            $grille = dynasty_pricing_load($db, false);
            dy_json_ok([
                'zones'    => dynasty_zones_load($db, $grille['categories']),
                'grille'   => $grille,
                'revision' => (int) ($grille['config']['zones_revision'] ?? 0),
            ]);
            break;
        }

        /* ═══════════ CARTE — contrat map-engine.js ═══════════ */

        case 'save_element': {
            dy_require('zone.create');
            if ((string) ($body['type'] ?? '') !== 'zone') {
                dy_json_err("Seules les zones sont gérées par cette carte.");
            }
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $pts  = dy_points($data['points'] ?? null);
            if ($pts === null) {
                dy_json_err("Tracé invalide : il faut au moins 3 sommets valides.");
            }
            $nom   = dy_text($data['title'] ?? '', 150) ?: 'Zone';
            $meta  = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            $catId = dy_categorie_id($db, $meta['categorie_id'] ?? 0);
            $bg    = dy_text(dynasty_pricing_setting(dynasty_pricing_load($db, false), 'background', 'atlas'), 20);

            // La couleur n'est stockée que si elle diffère de celle de la catégorie :
            // sinon la zone doit suivre automatiquement la couleur du quartier.
            $couleur = dy_hex($data['color'] ?? null);
            if ($catId !== null && $couleur !== null) {
                $cs = $db->prepare("SELECT couleur FROM dynasty_zone_categories WHERE id = ?");
                $cs->execute([$catId]);
                if (strcasecmp((string) $cs->fetchColumn(), $couleur) === 0) {
                    $couleur = null;
                }
            }

            $db->prepare("INSERT INTO dynasty_zones
                            (nom, categorie_id, points, background, couleur_override, created_by, updated_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$nom, $catId, json_encode($pts), $bg, $couleur, $uid, $uid]);

            $newId = (int) $db->lastInsertId();
            // Une zone qui vient d'apparaître peut correspondre à des biens
            // déjà saisis avec ce nom de quartier.
            dynasty_zones_match_biens($db);
            dy_json_ok(['id' => $newId, 'revision' => dynasty_zones_bump_revision($db)]);
            break;
        }

        case 'update_element': {
            dy_require('zone.edit');
            $zid = (int) ($body['element_id'] ?? 0);
            $st  = $db->prepare("SELECT id, categorie_id, nom FROM dynasty_zones WHERE id = ?");
            $st->execute([$zid]);
            $zone = $st->fetch(PDO::FETCH_ASSOC);
            if (!$zone) {
                dy_json_err('Zone introuvable.', 404);
            }

            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $sets = [];
            $args = [];

            if (array_key_exists('points', $data)) {
                $pts = dy_points($data['points']);
                if ($pts === null) {
                    dy_json_err("Tracé invalide : il faut au moins 3 sommets valides.");
                }
                $sets[] = 'points = ?'; $args[] = json_encode($pts);
            }
            $renomme = false;
            if (array_key_exists('title', $data)) {
                $nom = dy_text($data['title'], 150) ?: 'Zone';
                $renomme = ($nom !== (string) $zone['nom']);
                $sets[] = 'nom = ?'; $args[] = $nom;
            }
            $catId = $zone['categorie_id'] !== null ? (int) $zone['categorie_id'] : null;
            if (isset($data['meta']) && is_array($data['meta']) && array_key_exists('categorie_id', $data['meta'])) {
                $catId  = dy_categorie_id($db, $data['meta']['categorie_id']);
                $sets[] = 'categorie_id = ?'; $args[] = $catId;
            }
            if (array_key_exists('color', $data)) {
                $couleur = dy_hex($data['color']);
                if ($catId !== null && $couleur !== null) {
                    $cs = $db->prepare("SELECT couleur FROM dynasty_zone_categories WHERE id = ?");
                    $cs->execute([$catId]);
                    if (strcasecmp((string) $cs->fetchColumn(), $couleur) === 0) {
                        $couleur = null;
                    }
                }
                $sets[] = 'couleur_override = ?'; $args[] = $couleur;
            }

            if (!$sets) {
                dy_json_err('Aucune donnée à mettre à jour.');
            }
            $sets[] = 'updated_by = ?';  $args[] = $uid;
            $sets[] = 'revision = revision + 1';
            $args[] = $zid;
            $db->prepare("UPDATE dynasty_zones SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);

            if ($renomme) {
                dynasty_zones_match_biens($db);
            }
            dy_json_ok(['revision' => dynasty_zones_bump_revision($db)]);
            break;
        }

        case 'delete_element': {
            dy_require('zone.delete');
            $zid = (int) ($body['element_id'] ?? 0);
            if ($zid <= 0) {
                dy_json_err('Zone introuvable.', 404);
            }
            // Les biens rattachés sont détachés, jamais supprimés.
            if (dynasty_zones_biens_has_zone_id($db)) {
                $db->prepare("UPDATE dynasty_biens SET zone_id = NULL WHERE zone_id = ?")->execute([$zid]);
            }
            $db->prepare("DELETE FROM dynasty_zones WHERE id = ?")->execute([$zid]);
            dy_json_ok(['revision' => dynasty_zones_bump_revision($db)]);
            break;
        }

        /* ═══════════ TARIFICATION — CATÉGORIES ═══════════ */

        case 'pricing_save_category': {
            $id = (int) ($body['id'] ?? 0);
            dy_require($id > 0 ? 'zone.pricing_edit' : 'zone.pricing_add');

            $label = dy_text($body['label'] ?? '', 80);
            if ($label === '') {
                dy_json_err('Le libellé est obligatoire.');
            }
            $couleur = dy_hex($body['couleur'] ?? null, '#94a3b8');
            $loc     = dy_dec($body['prix_base_location'] ?? 0, 0, 9999999999);
            $ach     = dy_dec($body['prix_base_achat'] ?? 0, 0, 9999999999);
            $ordre   = (int) ($body['ordre'] ?? 0);
            $actif   = !empty($body['actif']) ? 1 : 0;

            if ($id > 0) {
                $db->prepare("UPDATE dynasty_zone_categories
                                 SET label = ?, couleur = ?, prix_base_location = ?, prix_base_achat = ?,
                                     ordre = ?, actif = ?
                               WHERE id = ?")
                   ->execute([$label, $couleur, $loc, $ach, $ordre, $actif, $id]);
            } else {
                $cle = dy_cle($body['cle'] ?? $label, 'categorie_' . time());
                // Clé déjà prise → on la suffixe plutôt que de rejeter la saisie.
                $chk = $db->prepare("SELECT COUNT(*) FROM dynasty_zone_categories WHERE cle = ?");
                $chk->execute([$cle]);
                if ((int) $chk->fetchColumn() > 0) {
                    $cle = mb_substr($cle, 0, 44) . '_' . substr((string) time(), -4);
                }
                $db->prepare("INSERT INTO dynasty_zone_categories
                                (cle, label, couleur, prix_base_location, prix_base_achat, ordre, actif)
                              VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$cle, $label, $couleur, $loc, $ach, $ordre, $actif]);
                $id = (int) $db->lastInsertId();
            }
            dynasty_zones_bump_revision($db);
            dy_json_ok(['id' => $id]);
            break;
        }

        case 'pricing_delete_category': {
            dy_require('zone.pricing_delete');
            $id = (int) ($body['id'] ?? 0);
            $vers = (int) ($body['reassign_to'] ?? 0);   // catégorie de repli, facultative

            $st = $db->prepare("SELECT id, nom FROM dynasty_zones WHERE categorie_id = ? ORDER BY nom ASC");
            $st->execute([$id]);
            $utilisees = $st->fetchAll(PDO::FETCH_ASSOC);

            if ($utilisees && $vers <= 0) {
                // 409 : le client doit proposer une réaffectation avant de réessayer.
                dy_json_err('Cette catégorie est encore utilisée par des zones.', 409, [
                    'zones' => array_map(static fn($z) => ['id' => (int) $z['id'], 'nom' => $z['nom']], $utilisees),
                ]);
            }
            if ($utilisees) {
                $cible = dy_categorie_id($db, $vers);
                if ($cible === null || $cible === $id) {
                    dy_json_err('Catégorie de réaffectation invalide.');
                }
                $db->prepare("UPDATE dynasty_zones SET categorie_id = ? WHERE categorie_id = ?")
                   ->execute([$cible, $id]);
            }
            $db->prepare("DELETE FROM dynasty_zone_categories WHERE id = ?")->execute([$id]);
            dynasty_zones_bump_revision($db);
            dy_json_ok(['reassigned' => count($utilisees)]);
            break;
        }

        /* ═══════════ TARIFICATION — OPTIONS ═══════════ */

        case 'pricing_save_option': {
            $id = (int) ($body['id'] ?? 0);
            dy_require($id > 0 ? 'zone.pricing_edit' : 'zone.pricing_add');

            $label = dy_text($body['label'] ?? '', 80);
            if ($label === '') {
                dy_json_err('Le libellé est obligatoire.');
            }
            $type = (($body['type'] ?? '') === 'multiplicateur') ? 'multiplicateur' : 'fixe';
            // Un multiplicateur est un facteur direct (1.15 = +15 %) : borné pour
            // qu'une saisie erronée ne produise pas un montant délirant.
            $bMin = ($type === 'multiplicateur') ? 0.0001 : 0.0;
            $bMax = ($type === 'multiplicateur') ? 100.0  : 9999999999.0;
            $vLoc = dy_dec($body['valeur_location'] ?? 0, $bMin, $bMax, $type === 'multiplicateur' ? 1.0 : 0.0);
            $vAch = dy_dec($body['valeur_achat'] ?? 0,    $bMin, $bMax, $type === 'multiplicateur' ? 1.0 : 0.0);
            $ordre = (int) ($body['ordre'] ?? 0);
            $actif = !empty($body['actif']) ? 1 : 0;

            if ($id > 0) {
                $db->prepare("UPDATE dynasty_tarif_options
                                 SET label = ?, type = ?, valeur_location = ?, valeur_achat = ?, ordre = ?, actif = ?
                               WHERE id = ?")
                   ->execute([$label, $type, $vLoc, $vAch, $ordre, $actif, $id]);
            } else {
                $cle = dy_cle($body['cle'] ?? $label, 'option_' . time());
                $chk = $db->prepare("SELECT COUNT(*) FROM dynasty_tarif_options WHERE cle = ?");
                $chk->execute([$cle]);
                if ((int) $chk->fetchColumn() > 0) {
                    $cle = mb_substr($cle, 0, 44) . '_' . substr((string) time(), -4);
                }
                $db->prepare("INSERT INTO dynasty_tarif_options
                                (cle, label, type, valeur_location, valeur_achat, ordre, actif)
                              VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$cle, $label, $type, $vLoc, $vAch, $ordre, $actif]);
                $id = (int) $db->lastInsertId();
            }
            dynasty_zones_bump_revision($db);
            dy_json_ok(['id' => $id]);
            break;
        }

        case 'pricing_delete_option': {
            dy_require('zone.pricing_delete');
            $db->prepare("DELETE FROM dynasty_tarif_options WHERE id = ?")->execute([(int) ($body['id'] ?? 0)]);
            dynasty_zones_bump_revision($db);
            dy_json_ok();
            break;
        }

        /* ═══════════ TARIFICATION — GARAGES ═══════════ */

        case 'pricing_save_garage': {
            $id = (int) ($body['id'] ?? 0);
            dy_require($id > 0 ? 'zone.pricing_edit' : 'zone.pricing_add');

            $places = (int) ($body['places'] ?? 0);
            if ($places < 0 || $places > 999) {
                dy_json_err('Nombre de places invalide.');
            }
            $sLoc  = dy_dec($body['supplement_location'] ?? 0, 0, 9999999999);
            $sAch  = dy_dec($body['supplement_achat'] ?? 0,    0, 9999999999);
            $icone = dy_text($body['icone'] ?? '', 80) ?: null;
            $ordre = (int) ($body['ordre'] ?? $places);
            $actif = !empty($body['actif']) ? 1 : 0;

            // places est UNIQUE : on refuse proprement plutôt que de laisser
            // remonter une erreur SQL brute.
            $chk = $db->prepare("SELECT id FROM dynasty_tarif_garages WHERE places = ? AND id <> ?");
            $chk->execute([$places, $id]);
            if ($chk->fetchColumn()) {
                dy_json_err("Un palier à {$places} places existe déjà.", 409);
            }

            if ($id > 0) {
                $db->prepare("UPDATE dynasty_tarif_garages
                                 SET places = ?, supplement_location = ?, supplement_achat = ?,
                                     icone = ?, ordre = ?, actif = ?
                               WHERE id = ?")
                   ->execute([$places, $sLoc, $sAch, $icone, $ordre, $actif, $id]);
            } else {
                $db->prepare("INSERT INTO dynasty_tarif_garages
                                (places, supplement_location, supplement_achat, icone, ordre, actif)
                              VALUES (?, ?, ?, ?, ?, ?)")
                   ->execute([$places, $sLoc, $sAch, $icone, $ordre, $actif]);
                $id = (int) $db->lastInsertId();
            }
            dynasty_zones_bump_revision($db);
            dy_json_ok(['id' => $id]);
            break;
        }

        case 'pricing_delete_garage': {
            dy_require('zone.pricing_delete');
            $db->prepare("DELETE FROM dynasty_tarif_garages WHERE id = ?")->execute([(int) ($body['id'] ?? 0)]);
            dynasty_zones_bump_revision($db);
            dy_json_ok();
            break;
        }

        /* ═══════════ TARIFICATION — RÉGLAGES GLOBAUX ═══════════ */

        case 'pricing_save_config': {
            dy_require('zone.pricing_edit');

            // Liste blanche : zones_revision est un compteur interne, jamais
            // modifiable depuis le client.
            $maj = [];
            if (isset($body['taxe'])) {
                $maj['taxe'] = (string) dy_dec($body['taxe'], 0, 10, 0.30);
            }
            if (isset($body['devise'])) {
                $maj['devise'] = dy_text($body['devise'], 8) ?: '$';
            }
            if (isset($body['multiplicateur_achat'])) {
                $maj['multiplicateur_achat'] = (string) dy_dec($body['multiplicateur_achat'], 0.0001, 1000, 1.0);
            }
            if (isset($body['background'])) {
                $bg = strtolower(dy_text($body['background'], 20));
                $maj['background'] = in_array($bg, ['atlas', 'roadmap', 'satellite'], true) ? $bg : 'atlas';
            }
            if (!$maj) {
                dy_json_err('Aucun réglage à enregistrer.');
            }
            $ins = $db->prepare("INSERT INTO dynasty_tarif_config (cle, valeur) VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)");
            foreach ($maj as $k => $v) {
                $ins->execute([$k, $v]);
            }
            dynasty_zones_bump_revision($db);
            dy_json_ok(['config' => $maj]);
            break;
        }

        default:
            dy_json_err('Action inconnue.', 404);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[Dynasty Zones][api] ' . $e->getMessage());
    dy_json_err('Erreur serveur.', 500);
}
