<?php
/**
 * LSPD — Helpers de gestion des prises / fins de service.
 *
 * Utilise `company_shifts` (faction='LSPD') comme table principale et
 * `lspd_service_partners` comme table pivot pour les binômes multiples.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/mdt_display_name.php';

/** Zones de patrouille autorisées. */
function lspd_service_zones(): array
{
    return [
        'Secteur 10',
        'Secteur 14',
        'Secteur 16',
        'Secteur 30',
        'Secteur 35',
        'Secteur 36',
    ];
}

/** Accès à la page / aux endpoints (LSPD ou staff). */
function lspd_service_access_ok(): bool
{
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return false;
    }
    $f = $_SESSION['user_faction'] ?? '';
    if (in_array($f, ['LSPD', 'LSSD', 'DOJ'], true)) {
        return true;
    }
    return hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper']);
}

/** Seuls les membres LSPD peuvent prendre un service LSPD. */
function lspd_service_can_start(): bool
{
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return false;
    }
    $f = (string) ($_SESSION['user_faction'] ?? '');
    if ($f === 'LSPD') {
        return true;
    }
    // Les staff peuvent simuler un service pour tests.
    return hasRole(['*', 'Administrateur', 'Développeur']);
}

function lspd_service_json_out(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function lspd_service_ensure_csrf(): string
{
    if (empty($_SESSION['lspd_service_csrf']) || !is_string($_SESSION['lspd_service_csrf'])) {
        $_SESSION['lspd_service_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['lspd_service_csrf'];
}

function lspd_service_verify_csrf(?string $t): bool
{
    $s = $_SESSION['lspd_service_csrf'] ?? '';
    return is_string($t) && $s !== '' && hash_equals($s, $t);
}

/** Crée la table de binômes si elle manque (auto-migration sûre). */
function lspd_service_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $db->exec('
            CREATE TABLE IF NOT EXISTS `lspd_service_partners` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `shift_id` INT(11) NOT NULL,
                `partner_user_id` INT(11) NOT NULL,
                `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `removed_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_shift_active` (`shift_id`, `removed_at`),
                KEY `idx_partner_active` (`partner_user_id`, `removed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    } catch (Throwable $e) {
        error_log('lspd_service_ensure_schema: ' . $e->getMessage());
    }
    $done = true;
}

/** Récupère le shift LSPD actif d'un utilisateur (ou null). */
function lspd_service_active_shift(PDO $db, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    // shift_start_ts = instant absolu (Unix epoch) calculé sans dépendance
    // au fuseau de session MySQL : on part de UNIX_TIMESTAMP() (instant
    // absolu) et on soustrait les secondes écoulées depuis shift_start
    // via TIMESTAMPDIFF (les deux opérandes partagent le même référentiel,
    // donc la différence est insensible au fuseau).
    $st = $db->prepare("
        SELECT
            id,
            user_id,
            shift_start,
            (UNIX_TIMESTAMP() - TIMESTAMPDIFF(SECOND, shift_start, NOW())) AS shift_start_ts,
            patrol_vehicle,
            patrol_zone,
            notes
        FROM company_shifts
        WHERE user_id = ? AND faction = 'LSPD' AND shift_end IS NULL
        ORDER BY shift_start DESC
        LIMIT 1
    ");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Horodatage Unix côté serveur SQL (évite tout décalage avec PHP). */
function lspd_service_now_ts(PDO $db): int
{
    try {
        return (int) $db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    } catch (Throwable $e) {
        return time();
    }
}

/**
 * Retourne la liste des officiers LSPD actuellement en service, avec leurs
 * binômes actifs (affichage BIDIRECTIONNEL : si A a ajouté B, B voit A aussi).
 *
 * Chaque officier reçoit :
 *   - `partners` : liste symétrique (tous les agents avec qui il est en binôme)
 *   - `busy_with_user_id` : user_id du shift dont il est référencé comme
 *      partner_user_id (utile pour afficher "Avec X" dans le picker)
 *
 * Les timestamps exposés (`shift_start_ts`, `now_ts`) sont des Unix epoch
 * calculés côté MySQL pour éviter tout décalage de fuseau horaire.
 *
 * @return array<int, array<string, mixed>>
 */
function lspd_service_list_active(PDO $db): array
{
    lspd_service_ensure_schema($db);

    // shift_start_ts = instant absolu (Unix epoch) insensible au fuseau de
    // session MySQL. Voir commentaire dans lspd_service_active_shift().
    $st = $db->query("
        SELECT
            cs.id                          AS shift_id,
            cs.user_id                     AS user_id,
            cs.shift_start                 AS shift_start,
            (UNIX_TIMESTAMP() - TIMESTAMPDIFF(SECOND, cs.shift_start, NOW())) AS shift_start_ts,
            cs.patrol_vehicle              AS patrol_vehicle,
            cs.patrol_zone                 AS patrol_zone,
            cs.notes                       AS notes,
            u.username                     AS username,
            u.faction_rank                 AS faction_rank,
            p.firstname                    AS profile_firstname,
            p.lastname                     AS profile_lastname
        FROM company_shifts cs
        JOIN users u ON u.id = cs.user_id
        LEFT JOIN profiles p ON p.id = u.profile_id
        WHERE cs.faction = 'LSPD' AND cs.shift_end IS NULL
        ORDER BY cs.shift_start ASC
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return [];
    }

    // Index : user_id → données de base (pour résolution symétrique)
    $byUser = [];
    foreach ($rows as $r) {
        $uid = (int) $r['user_id'];
        $byUser[$uid] = [
            'user_id'      => $uid,
            'shift_id'     => (int) $r['shift_id'],
            'username'     => (string) $r['username'],
            'faction_rank' => (string) ($r['faction_rank'] ?? ''),
            'display_name' => mdt_rp_display_name(
                $r['profile_firstname'] ?? null,
                $r['profile_lastname'] ?? null,
                (string) $r['username']
            ),
        ];
    }

    // Index : shift_id → user_id (pour déterminer le "lead" d'un binôme)
    $shiftOwner = [];
    foreach ($rows as $r) {
        $shiftOwner[(int) $r['shift_id']] = (int) $r['user_id'];
    }

    // Récupère TOUS les liens de binôme actifs parmi les shifts actifs.
    $shiftIds = array_keys($shiftOwner);
    $in = implode(',', array_fill(0, count($shiftIds), '?'));
    $pst = $db->prepare("
        SELECT sp.shift_id, sp.partner_user_id
        FROM lspd_service_partners sp
        WHERE sp.removed_at IS NULL
          AND sp.shift_id IN ($in)
    ");
    $pst->execute($shiftIds);

    // Construit un graphe d'adjacence symétrique + l'index `busy_with`.
    $adj = [];          // user_id => set of user_id
    $busyWith = [];     // partner_user_id => leadUserId (le shift qui l'a recruté)
    foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $leadUid = $shiftOwner[(int) $row['shift_id']] ?? 0;
        $partUid = (int) $row['partner_user_id'];
        if ($leadUid <= 0 || $partUid <= 0 || $leadUid === $partUid) {
            continue;
        }
        // Lien symétrique A ↔ B
        $adj[$leadUid][$partUid] = true;
        $adj[$partUid][$leadUid] = true;
        // Qui a recruté ce partenaire (le lead possède la "propriété" du binôme)
        $busyWith[$partUid] = $leadUid;
    }

    $out = [];
    foreach ($rows as $r) {
        $uid = (int) $r['user_id'];
        $shiftId = (int) $r['shift_id'];

        $partnerList = [];
        if (!empty($adj[$uid])) {
            foreach (array_keys($adj[$uid]) as $pUid) {
                if (!isset($byUser[$pUid])) {
                    continue;
                }
                $info = $byUser[$pUid];
                $partnerList[] = [
                    'user_id'      => (int) $info['user_id'],
                    'username'     => (string) $info['username'],
                    'faction_rank' => (string) $info['faction_rank'],
                    'display_name' => (string) $info['display_name'],
                ];
            }
        }

        $out[] = [
            'shift_id'          => $shiftId,
            'user_id'           => $uid,
            'shift_start'       => (string) $r['shift_start'],
            'shift_start_ts'    => (int) $r['shift_start_ts'],
            'patrol_vehicle'    => (string) ($r['patrol_vehicle'] ?? ''),
            'patrol_zone'       => (string) ($r['patrol_zone'] ?? ''),
            'notes'             => (string) ($r['notes'] ?? ''),
            'username'          => (string) $r['username'],
            'faction_rank'      => (string) ($r['faction_rank'] ?? ''),
            'display_name'      => mdt_rp_display_name(
                $r['profile_firstname'] ?? null,
                $r['profile_lastname'] ?? null,
                (string) $r['username']
            ),
            'partners'          => $partnerList,
            'busy_with_user_id' => $busyWith[$uid] ?? null,
        ];
    }
    return $out;
}

/**
 * Historique des 20 derniers services d'un utilisateur, avec binômes archivés.
 *
 * @return array<int, array<string, mixed>>
 */
function lspd_service_history(PDO $db, int $userId, int $limit = 20): array
{
    lspd_service_ensure_schema($db);

    if ($userId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));

    $st = $db->prepare("
        SELECT id, shift_start, shift_end, duration_minutes, patrol_vehicle, patrol_zone, notes
        FROM company_shifts
        WHERE faction = 'LSPD' AND user_id = ? AND shift_end IS NOT NULL
        ORDER BY shift_start DESC
        LIMIT $limit
    ");
    $st->execute([$userId]);
    $shifts = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$shifts) {
        return [];
    }

    $shiftIds = array_column($shifts, 'id');
    $byShift = [];
    if ($shiftIds) {
        $in = implode(',', array_fill(0, count($shiftIds), '?'));
        $pst = $db->prepare("
            SELECT
                sp.shift_id AS shift_id,
                u.username  AS username,
                p.firstname AS profile_firstname,
                p.lastname  AS profile_lastname
            FROM lspd_service_partners sp
            JOIN users u ON u.id = sp.partner_user_id
            LEFT JOIN profiles p ON p.id = u.profile_id
            WHERE sp.shift_id IN ($in)
            GROUP BY sp.shift_id, sp.partner_user_id
        ");
        $pst->execute($shiftIds);
        foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $sid = (int) $p['shift_id'];
            $byShift[$sid][] = mdt_rp_display_name(
                $p['profile_firstname'] ?? null,
                $p['profile_lastname'] ?? null,
                (string) $p['username']
            );
        }
    }

    $out = [];
    foreach ($shifts as $s) {
        $sid = (int) $s['id'];
        $out[] = [
            'shift_id'         => $sid,
            'shift_start'      => (string) $s['shift_start'],
            'shift_end'        => (string) $s['shift_end'],
            'duration_minutes' => (int) ($s['duration_minutes'] ?? 0),
            'patrol_vehicle'   => (string) ($s['patrol_vehicle'] ?? ''),
            'patrol_zone'      => (string) ($s['patrol_zone'] ?? ''),
            'notes'            => (string) ($s['notes'] ?? ''),
            'partners'         => $byShift[$sid] ?? [],
        ];
    }
    return $out;
}

/**
 * Met à jour la liste des binômes actifs d'un shift donné.
 * Garantit qu'un binôme n'appartient qu'à un seul shift actif (il est
 * retiré de son shift précédent automatiquement).
 *
 * @param array<int, int> $desiredPartnerIds Liste brute de partner_user_id
 * @return array{partners:array<int,int>, freed:array<int,int>}
 */
function lspd_service_set_partners(PDO $db, int $shiftId, int $ownerUserId, array $desiredPartnerIds): array
{
    lspd_service_ensure_schema($db);

    // Normalise et filtre.
    $desired = [];
    foreach ($desiredPartnerIds as $pid) {
        $pid = (int) $pid;
        if ($pid > 0 && $pid !== $ownerUserId) {
            $desired[$pid] = true;
        }
    }
    $desiredIds = array_keys($desired);

    if ($desiredIds) {
        // Vérifie : tous les binômes demandés doivent avoir un shift LSPD actif.
        $in = implode(',', array_fill(0, count($desiredIds), '?'));
        $chk = $db->prepare("
            SELECT user_id FROM company_shifts
            WHERE faction = 'LSPD' AND shift_end IS NULL AND user_id IN ($in)
        ");
        $chk->execute($desiredIds);
        $activeUserIds = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));
        $activeSet = array_fill_keys($activeUserIds, true);

        $desiredIds = array_values(array_filter(
            $desiredIds,
            static fn ($id) => isset($activeSet[$id])
        ));
    }

    $db->beginTransaction();
    try {
        $freed = [];

        // ── 1. Collecte TOUS les liens symétriques actifs touchant
        //      l'utilisateur courant : ceux qu'il a créés (shift=mine) et
        //      ceux où il est apparu comme binôme (partner_user_id=moi).
        $my = $db->prepare('
            SELECT id, partner_user_id AS other
            FROM lspd_service_partners
            WHERE shift_id = ? AND removed_at IS NULL
        ');
        $my->execute([$shiftId]);
        $myRows = $my->fetchAll(PDO::FETCH_ASSOC);

        $inv = $db->prepare('
            SELECT sp.id, cs.user_id AS other
            FROM lspd_service_partners sp
            JOIN company_shifts cs ON cs.id = sp.shift_id
            WHERE sp.partner_user_id = ? AND sp.removed_at IS NULL
              AND cs.shift_end IS NULL
        ');
        $inv->execute([$ownerUserId]);
        $invRows = $inv->fetchAll(PDO::FETCH_ASSOC);

        // user_id courant (symétrique) → liste de row ids à fermer si retrait
        $currentByUser = [];
        foreach ($myRows as $r) {
            $currentByUser[(int) $r['other']][] = (int) $r['id'];
        }
        foreach ($invRows as $r) {
            $currentByUser[(int) $r['other']][] = (int) $r['id'];
        }
        $currentIds = array_keys($currentByUser);
        $desiredSet = array_fill_keys($desiredIds, true);

        // ── 2. Retrait : pour chaque partenaire courant NON désiré,
        //      fermer toutes les lignes actives entre lui et moi.
        foreach ($currentIds as $uidCur) {
            if (!isset($desiredSet[$uidCur])) {
                foreach ($currentByUser[$uidCur] as $rowId) {
                    $db->prepare('UPDATE lspd_service_partners SET removed_at = NOW() WHERE id = ?')
                       ->execute([$rowId]);
                }
            }
        }
        $currentSet = array_fill_keys($currentIds, true);

        // ── 3. Ajouts : pour chaque nouveau binôme désiré,
        //      a) libère-le de tout autre shift actif,
        //      b) insère une ligne (mon_shift, lui).
        foreach ($desiredIds as $pid) {
            if (isset($currentSet[$pid])) {
                continue; // déjà lié à moi (forward ou reverse)
            }

            $other = $db->prepare('
                SELECT sp.id, sp.shift_id
                FROM lspd_service_partners sp
                JOIN company_shifts cs ON cs.id = sp.shift_id
                WHERE sp.partner_user_id = ?
                  AND sp.removed_at IS NULL
                  AND cs.shift_end IS NULL
            ');
            $other->execute([$pid]);
            foreach ($other->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $db->prepare('UPDATE lspd_service_partners SET removed_at = NOW() WHERE id = ?')
                   ->execute([(int) $o['id']]);
                $freed[$pid] = (int) $o['shift_id'];
            }

            $ins = $db->prepare('INSERT INTO lspd_service_partners (shift_id, partner_user_id) VALUES (?, ?)');
            $ins->execute([$shiftId, $pid]);
        }

        $db->commit();

        return ['partners' => array_values($desiredIds), 'freed' => $freed];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Liste les officiers LSPD actuellement en service utilisables comme
 * binômes (exclut l'utilisateur courant), avec leur shift actif et
 * leurs binômes actuels.
 */
function lspd_service_selectable_officers(PDO $db, int $excludeUserId): array
{
    $list = lspd_service_list_active($db);
    $out = [];
    foreach ($list as $o) {
        if ((int) $o['user_id'] === $excludeUserId) {
            continue;
        }
        $out[] = [
            'user_id'      => (int) $o['user_id'],
            'shift_id'     => (int) $o['shift_id'],
            'display_name' => (string) $o['display_name'],
            'username'     => (string) $o['username'],
            'faction_rank' => (string) $o['faction_rank'],
        ];
    }
    return $out;
}

/**
 * Statistiques de temps de service (en minutes) pour un utilisateur.
 *
 * Calculs côté MySQL (YEARWEEK mode 3 = ISO semaine lundi→dimanche) pour
 * rester indépendant du fuseau PHP. Le shift actif en cours est ajouté
 * à la semaine / au mois en cours si applicable.
 *
 * @return array{
 *   week:int, month:int, prev_month:int, total:int,
 *   active_minutes:int, by_zone:array<int, array{zone:string, minutes:int}>
 * }
 */
function lspd_service_stats(PDO $db, int $userId): array
{
    lspd_service_ensure_schema($db);

    $empty = [
        'week'           => 0,
        'month'          => 0,
        'prev_month'     => 0,
        'total'          => 0,
        'active_minutes' => 0,
        'by_zone'        => [],
    ];
    if ($userId <= 0) {
        return $empty;
    }

    // Agrégations SQL en un seul round-trip.
    $sql = "
        SELECT
            COALESCE(SUM(CASE
                WHEN YEARWEEK(shift_start, 3) = YEARWEEK(CURDATE(), 3)
                THEN duration_minutes ELSE 0 END), 0)          AS week_min,
            COALESCE(SUM(CASE
                WHEN YEAR(shift_start) = YEAR(CURDATE())
                 AND MONTH(shift_start) = MONTH(CURDATE())
                THEN duration_minutes ELSE 0 END), 0)          AS month_min,
            COALESCE(SUM(CASE
                WHEN DATE_FORMAT(shift_start, '%Y-%m') =
                     DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')
                THEN duration_minutes ELSE 0 END), 0)          AS prev_month_min,
            COALESCE(SUM(duration_minutes), 0)                 AS total_min
        FROM company_shifts
        WHERE faction = 'LSPD' AND user_id = ? AND shift_end IS NOT NULL
    ";
    try {
        $st = $db->prepare($sql);
        $st->execute([$userId]);
        $agg = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('lspd_service_stats: ' . $e->getMessage());
        return $empty;
    }

    // Shift actif : ajoute ses minutes aux compteurs semaine/mois en cours
    // s'il démarre dans la fenêtre courante (sinon on ignore pour rester fidèle).
    $activeMin = 0;
    try {
        $a = $db->prepare("
            SELECT
                TIMESTAMPDIFF(MINUTE, shift_start, NOW())          AS mins,
                YEARWEEK(shift_start, 3) = YEARWEEK(CURDATE(), 3)  AS in_week,
                YEAR(shift_start)  = YEAR(CURDATE())
                AND MONTH(shift_start) = MONTH(CURDATE())          AS in_month
            FROM company_shifts
            WHERE faction = 'LSPD' AND user_id = ? AND shift_end IS NULL
            ORDER BY shift_start DESC
            LIMIT 1
        ");
        $a->execute([$userId]);
        if ($r = $a->fetch(PDO::FETCH_ASSOC)) {
            $activeMin = max(0, (int) $r['mins']);
            if ($activeMin > 0) {
                if ((int) $r['in_week'] === 1) {
                    $agg['week_min']  = (int) $agg['week_min']  + $activeMin;
                }
                if ((int) $r['in_month'] === 1) {
                    $agg['month_min'] = (int) $agg['month_min'] + $activeMin;
                }
                $agg['total_min'] = (int) $agg['total_min'] + $activeMin;
            }
        }
    } catch (Throwable $e) {
        error_log('lspd_service_stats(active): ' . $e->getMessage());
    }

    // Répartition par zone (uniquement shifts terminés, 6 principales).
    $byZone = [];
    try {
        $z = $db->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(patrol_zone), ''), 'Non précisée') AS zone,
                COALESCE(SUM(duration_minutes), 0) AS mins
            FROM company_shifts
            WHERE faction = 'LSPD' AND user_id = ? AND shift_end IS NOT NULL
            GROUP BY zone
            ORDER BY mins DESC
            LIMIT 8
        ");
        $z->execute([$userId]);
        foreach ($z->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byZone[] = [
                'zone'    => (string) $row['zone'],
                'minutes' => (int) $row['mins'],
            ];
        }
    } catch (Throwable $e) {
        error_log('lspd_service_stats(byZone): ' . $e->getMessage());
    }

    return [
        'week'           => (int) ($agg['week_min']       ?? 0),
        'month'          => (int) ($agg['month_min']      ?? 0),
        'prev_month'     => (int) ($agg['prev_month_min'] ?? 0),
        'total'          => (int) ($agg['total_min']      ?? 0),
        'active_minutes' => $activeMin,
        'by_zone'        => $byZone,
    ];
}

/* ═══════════════════════════════════════════════════════════════════════════
 * SAISON & PAIE (Phase 3)
 *
 * « Saison en cours » = période depuis le dernier reset de l'agent. On réutilise
 * intégralement company_shifts comme source des heures : la saison ne supprime
 * jamais de données, elle déplace seulement un marqueur `season_start`.
 *   - minutes saison  = SUM(duration_minutes des shifts démarrés >= season_start) (+ actif)
 *   - minutes lifetime = SUM(toutes les durées) (+ actif) — jamais remis à zéro
 * ═════════════════════════════════════════════════════════════════════════ */

/** Crée la table des compteurs de saison par agent (auto-migration). */
function lspd_service_totals_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `lspd_service_totals` (
                `user_id`      INT(11) NOT NULL,
                `season_start` DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00',
                `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) { error_log('lspd_service_totals_ensure: ' . $e->getMessage()); }
    $done = true;
}

/** Retourne (et crée si absent) le marqueur de début de saison d'un agent. */
function lspd_service_season_start(PDO $db, int $userId): string
{
    lspd_service_totals_ensure($db);
    try {
        $st = $db->prepare("SELECT season_start FROM lspd_service_totals WHERE user_id = ?");
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        if ($v) return (string) $v;
        $db->prepare("INSERT IGNORE INTO lspd_service_totals (user_id, season_start) VALUES (?, '2000-01-01 00:00:00')")
           ->execute([$userId]);
    } catch (Throwable $e) { error_log('lspd_service_season_start: ' . $e->getMessage()); }
    return '2000-01-01 00:00:00';
}

/**
 * Compteurs de temps d'un agent.
 * @return array{season_start:string, season:int, lifetime:int, active:int}
 */
function lspd_service_season(PDO $db, int $userId): array
{
    $start = lspd_service_season_start($db, $userId);
    $out = ['season_start' => $start, 'season' => 0, 'lifetime' => 0, 'active' => 0];
    if ($userId <= 0) return $out;
    try {
        $st = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN shift_start >= ? THEN duration_minutes ELSE 0 END), 0) AS season_min,
                COALESCE(SUM(duration_minutes), 0) AS life_min
            FROM company_shifts
            WHERE faction = 'LSPD' AND user_id = ? AND shift_end IS NOT NULL
        ");
        $st->execute([$start, $userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['season']   = (int) ($r['season_min'] ?? 0);
        $out['lifetime'] = (int) ($r['life_min'] ?? 0);
    } catch (Throwable $e) { error_log('lspd_service_season: ' . $e->getMessage()); }

    // Shift actif : compté dans lifetime, et dans la saison s'il a démarré après le reset.
    try {
        $a = $db->prepare("
            SELECT TIMESTAMPDIFF(MINUTE, shift_start, NOW()) AS mins,
                   (shift_start >= ?) AS in_season
            FROM company_shifts
            WHERE faction = 'LSPD' AND user_id = ? AND shift_end IS NULL
            ORDER BY shift_start DESC LIMIT 1
        ");
        $a->execute([$start, $userId]);
        if ($r = $a->fetch(PDO::FETCH_ASSOC)) {
            $m = max(0, (int) $r['mins']);
            $out['active']    = $m;
            $out['lifetime'] += $m;
            if ((int) $r['in_season'] === 1) $out['season'] += $m;
        }
    } catch (Throwable $e) { error_log('lspd_service_season(active): ' . $e->getMessage()); }

    return $out;
}

/** Réinitialise la saison d'un agent (le lifetime n'est pas affecté). */
function lspd_service_reset_season(PDO $db, int $userId): void
{
    lspd_service_totals_ensure($db);
    $db->prepare("
        INSERT INTO lspd_service_totals (user_id, season_start) VALUES (?, NOW())
        ON DUPLICATE KEY UPDATE season_start = NOW()
    ")->execute([$userId]);
}

/** Map rank_name => salaire horaire ($/h) depuis lspd_ranks (cf. divisions.php). */
function lspd_hourly_rate_map(PDO $db): array
{
    $map = [];
    try {
        foreach ($db->query("SELECT rank_name, salary_hourly FROM lspd_ranks")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[$r['rank_name']] = (float) $r['salary_hourly'];
        }
    } catch (Throwable $e) { /* table absente */ }
    return $map;
}
