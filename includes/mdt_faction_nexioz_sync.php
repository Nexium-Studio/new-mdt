<?php
declare(strict_types=1);

/**
 * Liaison des rôles de faction MDT ↔ NexioZ.
 *
 * Remplace l'ancienne « Synchronisation Discord B » (bot token stocké dans le
 * MDT + interrogation directe de l'API Discord d'un seul serveur). Ici, le MDT
 * délègue tout à NexioZ, qui voit l'ensemble des serveurs Discord où le bot est
 * présent. Le MDT est toujours l'initiateur des appels HTTP.
 *
 * On gère 4 liaisons indépendantes (« slots »), chacune portée par une clé de
 * liaison auto-suffisante `nxz_base64url({u,k})` — identique aux propositions.
 * La clé est générée dans NexioZ et collée dans le MDT.
 *
 * Modèle : mono-faction (colonne users.faction). En cas de correspondances
 * multiples, on prend la première selon l'ordre des slots (1 → 4). Une faction
 * auto-attribuée est retirée (→ NONE) si l'utilisateur perd le rôle Discord
 * lié ; les factions posées manuellement (non gérées par une liaison) sont
 * préservées.
 */

require_once __DIR__ . '/site_settings.php';   // mdt_admin_settings_fetch / upsert
require_once __DIR__ . '/mdt_proposals.php';   // mdt_proposals_parse_token (réutilisé)

const MDT_FACTION_NEXIOZ_SLOTS = 4;

/** Labels par défaut des 4 sections. */
function mdt_faction_nexioz_default_labels(): array
{
    return [
        1 => 'Services publics',
        2 => 'Organisations criminelles',
        3 => 'Entreprises légales',
        4 => 'Personnalisée',
    ];
}

function mdt_faction_nexioz_token_key(int $slot): string
{
    return 'nexioz_faction_liaison_token_' . $slot;
}
function mdt_faction_nexioz_label_key(int $slot): string
{
    return 'nexioz_faction_liaison_label_' . $slot;
}

/**
 * Crée la table de journal si besoin (idempotent, une fois par requête).
 */
function mdt_faction_nexioz_ensure_tables(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS nexioz_faction_sync_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                discord_id VARCHAR(100) NULL,
                username VARCHAR(190) NULL,
                old_faction VARCHAR(50) NULL,
                new_faction VARCHAR(50) NULL,
                source VARCHAR(30) NULL,
                synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_synced (synced_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // Journalisation best-effort : ne jamais bloquer la synchro.
    }
}

/**
 * Charge les 4 liaisons configurées.
 *
 * @return array<int,array{slot:int,label:string,token:string,parsed:?array{url:string,secret:string},linked:bool}>
 *         Indexé par numéro de slot (1..4), dans l'ordre.
 */
function mdt_faction_nexioz_liaisons(PDO $db): array
{
    $keys = [];
    for ($i = 1; $i <= MDT_FACTION_NEXIOZ_SLOTS; $i++) {
        $keys[] = mdt_faction_nexioz_token_key($i);
        $keys[] = mdt_faction_nexioz_label_key($i);
    }
    $rows = mdt_admin_settings_fetch($db, $keys);
    $defaults = mdt_faction_nexioz_default_labels();

    $out = [];
    for ($i = 1; $i <= MDT_FACTION_NEXIOZ_SLOTS; $i++) {
        $token = trim((string) ($rows[mdt_faction_nexioz_token_key($i)] ?? ''));
        $label = trim((string) ($rows[mdt_faction_nexioz_label_key($i)] ?? ''));
        if ($label === '') {
            $label = $defaults[$i];
        }
        $parsed = $token !== '' ? mdt_proposals_parse_token($token) : null;
        $out[$i] = [
            'slot'   => $i,
            'label'  => $label,
            'token'  => $token,
            'parsed' => $parsed,
            'linked' => $parsed !== null,
        ];
    }
    return $out;
}

/** Enregistre (ou vide) la clé + le label d'une liaison. */
function mdt_faction_nexioz_save_liaison(PDO $db, int $slot, string $token, ?string $label = null): void
{
    if ($slot < 1 || $slot > MDT_FACTION_NEXIOZ_SLOTS) {
        throw new InvalidArgumentException('Slot de liaison invalide.');
    }
    mdt_admin_settings_upsert($db, mdt_faction_nexioz_token_key($slot), trim($token));
    if ($label !== null) {
        mdt_admin_settings_upsert($db, mdt_faction_nexioz_label_key($slot), trim($label));
    }
}

/**
 * Liste des factions du MDT à transmettre à NexioZ.
 *
 * @return array<int,array{key:string,name:string,type:string,color:string,icon:string}>
 */
function mdt_faction_nexioz_faction_list(PDO $db): array
{
    try {
        $rows = $db->query(
            "SELECT faction_key, name, faction_type, color, icon FROM factions ORDER BY name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $key = trim((string) ($r['faction_key'] ?? ''));
        if ($key === '' || strcasecmp($key, 'NONE') === 0) {
            continue;
        }
        $out[] = [
            'key'   => $key,
            'name'  => (string) ($r['name'] ?? $key),
            'type'  => (string) ($r['faction_type'] ?? ''),
            'color' => (string) ($r['color'] ?? ''),
            'icon'  => (string) ($r['icon'] ?? ''),
        ];
    }
    return $out;
}

/**
 * POST JSON vers l'endpoint faction-roles du bot NexioZ.
 *
 * @param array<string,mixed> $payload
 * @return array{ok:bool,code:int,data:?array,error:string}
 */
function mdt_faction_nexioz_post(string $base, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'data' => null, 'error' => 'Extension cURL indisponible.'];
    }

    $base = (defined('NEXIOZ_API_BASE') && NEXIOZ_API_BASE !== '')
        ? rtrim((string) NEXIOZ_API_BASE, '/')
        : rtrim($base, '/');
    if ($base === '' || !preg_match('#^https?://#i', $base)) {
        return ['ok' => false, 'code' => 0, 'data' => null, 'error' => 'URL du bot invalide.'];
    }

    $endpoint = $base . '/api/mdt/faction-roles';
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'code' => 0, 'data' => null, 'error' => 'Encodage JSON impossible.'];
    }

    try {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['ok' => false, 'code' => $code, 'data' => null, 'error' => $err];
        }
        $data = is_string($body) ? json_decode($body, true) : null;
        $ok = $code >= 200 && $code < 300 && is_array($data) && !empty($data['ok']);
        return [
            'ok'    => $ok,
            'code'  => $code,
            'data'  => is_array($data) ? $data : null,
            'error' => $ok ? '' : ('HTTP ' . $code),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'code' => 0, 'data' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Pousse la liste des factions du MDT vers une liaison NexioZ.
 *
 * @param array{parsed:?array{url:string,secret:string}} $liaison
 * @return array{ok:bool,error:string,count:int}
 */
function mdt_faction_nexioz_push_factions(PDO $db, array $liaison): array
{
    if (($liaison['parsed'] ?? null) === null) {
        return ['ok' => false, 'error' => 'Liaison non configurée.', 'count' => 0];
    }
    $factions = mdt_faction_nexioz_faction_list($db);
    $res = mdt_faction_nexioz_post($liaison['parsed']['url'], [
        'secret'   => $liaison['parsed']['secret'],
        'action'   => 'sync_factions',
        'factions' => $factions,
        'site'     => defined('SITE_URL') ? SITE_URL : '',
    ]);

    $count = ($res['data']['count'] ?? null) !== null ? (int) $res['data']['count'] : count($factions);
    $error = $res['error'];
    if (!$res['ok'] && $res['code'] === 401) {
        $error = 'Secret refusé — activez la section « Rôles factions » dans NexioZ avec cette clé.';
    }
    return ['ok' => $res['ok'], 'error' => $error, 'count' => $count];
}

/**
 * Diagnostic (dryRun) d'une liaison : valide la clé sans effet de bord.
 *
 * @param array{parsed:?array{url:string,secret:string}} $liaison
 * @return array{ok:bool,error:string}
 */
function mdt_faction_nexioz_diagnose(array $liaison): array
{
    if (($liaison['parsed'] ?? null) === null) {
        return ['ok' => false, 'error' => 'Clé illisible ou absente (format attendu nxz_…).'];
    }
    $res = mdt_faction_nexioz_post($liaison['parsed']['url'], [
        'secret'    => $liaison['parsed']['secret'],
        'action'    => 'resolve',
        'discordId' => '0',
        'dryRun'    => true,
    ]);

    if ($res['ok']) {
        return ['ok' => true, 'error' => ''];
    }
    $msg = $res['error'] !== '' ? $res['error'] : 'Bot injoignable.';
    if ($res['code'] === 401) {
        $msg = 'Secret refusé — vérifiez que la section « Rôles factions » est activée dans NexioZ avec cette clé.';
    }
    return ['ok' => false, 'error' => $msg];
}

/**
 * Résout les factions d'un utilisateur auprès d'une base NexioZ.
 *
 * @param string[] $secrets
 * @return array{factions:string[],managed:string[]}
 */
function mdt_faction_nexioz_resolve(string $base, array $secrets, string $discordId): array
{
    $secrets = array_values(array_filter(array_map('strval', $secrets), static fn ($s) => $s !== ''));
    if ($secrets === [] || trim($discordId) === '') {
        return ['factions' => [], 'managed' => []];
    }

    $payload = [
        'action'    => 'resolve',
        'discordId' => $discordId,
        'secret'    => $secrets[0],
    ];
    if (count($secrets) > 1) {
        $payload['secrets'] = $secrets;
    }

    $res  = mdt_faction_nexioz_post($base, $payload);
    $data = $res['data'] ?? null;

    $factions = (is_array($data) && isset($data['factions']) && is_array($data['factions']))
        ? array_values(array_filter(array_map('strval', $data['factions']), static fn ($s) => $s !== ''))
        : [];
    $managed = (is_array($data) && isset($data['managed']) && is_array($data['managed']))
        ? array_values(array_filter(array_map('strval', $data['managed']), static fn ($s) => $s !== ''))
        : [];

    return ['factions' => $factions, 'managed' => $managed];
}

/** Journalise un changement de faction. */
function mdt_faction_nexioz_log(PDO $db, int $userId, string $discordId, string $old, string $new, string $source): void
{
    mdt_faction_nexioz_ensure_tables($db);
    try {
        $u = $db->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
        $u->execute([$userId]);
        $username = (string) ($u->fetchColumn() ?: '');

        $st = $db->prepare(
            "INSERT INTO nexioz_faction_sync_log (user_id, discord_id, username, old_faction, new_faction, source)
             VALUES (?,?,?,?,?,?)"
        );
        $st->execute([$userId, $discordId, $username, $old, $new, $source]);
    } catch (Throwable $e) {
        // best-effort
    }
}

/**
 * Synchronise la faction d'un utilisateur selon ses rôles Discord (via NexioZ).
 *
 * @return array{success:bool,changed:bool,old_faction:?string,new_faction:?string,message:string}
 */
function mdt_faction_nexioz_sync_user(PDO $db, int $userId, string $discordId, string $source = 'login'): array
{
    $result = [
        'success'     => true,
        'changed'     => false,
        'old_faction' => null,
        'new_faction' => null,
        'message'     => '',
    ];

    $discordId = trim($discordId);
    if ($userId <= 0 || $discordId === '') {
        $result['success'] = false;
        $result['message'] = 'Paramètres manquants.';
        return $result;
    }

    $liaisons   = mdt_faction_nexioz_liaisons($db);
    $configured = array_filter($liaisons, static fn ($l) => ($l['parsed'] ?? null) !== null);
    if ($configured === []) {
        $result['message'] = 'Aucune liaison configurée.';
        return $result;
    }

    // Regroupement par URL de bot (généralement une seule) : limite le nombre
    // d'appels au login tout en préservant l'ordre des slots (1 → 4) pour la
    // priorité mono-faction (NexioZ renvoie les factions dans l'ordre des secrets).
    $byBase    = [];
    $baseOrder = [];
    foreach ($configured as $l) {
        $base = $l['parsed']['url'];
        if (!isset($byBase[$base])) {
            $byBase[$base] = [];
            $baseOrder[]   = $base;
        }
        $byBase[$base][] = $l['parsed']['secret'];
    }

    $matched = [];
    $managed = [];
    foreach ($baseOrder as $base) {
        $r = mdt_faction_nexioz_resolve($base, $byBase[$base], $discordId);
        foreach ($r['factions'] as $fk) {
            if (!in_array($fk, $matched, true)) {
                $matched[] = $fk;
            }
        }
        foreach ($r['managed'] as $fk) {
            if (!in_array($fk, $managed, true)) {
                $managed[] = $fk;
            }
        }
    }

    // Faction actuelle
    $st = $db->prepare("SELECT faction FROM users WHERE id=? LIMIT 1");
    $st->execute([$userId]);
    $current = (string) ($st->fetchColumn() ?: 'NONE');
    $result['old_faction'] = $current;

    if ($matched !== []) {
        $new = $matched[0];                       // priorité : 1re correspondance (ordre des slots)
    } elseif (in_array($current, $managed, true)) {
        $new = 'NONE';                            // faction gérée mais rôle Discord perdu
    } else {
        $new = $current;                          // faction manuelle : préservée
    }
    $result['new_faction'] = $new;

    if ($new !== $current) {
        try {
            if ($new === 'NONE') {
                $db->prepare("UPDATE users SET faction='NONE', faction_rank=NULL, faction_grade=NULL WHERE id=?")
                   ->execute([$userId]);
            } else {
                $db->prepare("UPDATE users SET faction=? WHERE id=?")->execute([$new, $userId]);
            }
            $result['changed'] = true;
            mdt_faction_nexioz_log($db, $userId, $discordId, $current, $new, $source);

            if (function_exists('mdt_audit_log')) {
                try {
                    mdt_audit_log(
                        $userId,
                        'FACTION_SYNC',
                        'Synchronisation NexioZ',
                        "Faction : {$current} → {$new}",
                        'faction',
                        null
                    );
                } catch (Throwable $e) {
                    // best-effort
                }
            }
        } catch (Throwable $e) {
            $result['success'] = false;
            $result['message'] = 'Erreur de mise à jour : ' . $e->getMessage();
        }
    }

    return $result;
}
