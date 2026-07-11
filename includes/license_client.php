<?php
// V1 MDT/includes/license_client.php
//
// Client leger pour interagir avec api.nexium-studio.com.
// Le MDT n'a qu'UNE seule cle (licence master). Le statut de chaque produit
// (MDT principal : mdt_1month, mdt_lifetime, etc. + addons) est recupere
// via /api/v1/license/status.php puis mis en cache dans `admin_settings`.

class License
{
    /** URL de l'API centrale Nexium */
    const API_BASE = 'https://api.nexium-studio.com';

    /** Duree de cache (secondes) avant un re-check automatique. */
    const CACHE_TTL = 3600; // 1h

    /** Addons MDT (parent_slug=mdt cote BDD) : exigent une ligne MDT active. */
    private const MDT_DEPENDENT_ADDONS = ['addon_hack_mdt', 'addon_mail', 'addon_dispatch'];

    private static ?array $cache = null;

    // =================================================================
    //   Slugs MDT (ligne "core" : toutes les declinaisons d'abonnement)
    // =================================================================

    private static function isMdtCoreProductSlug(string $slug): bool
    {
        if ($slug === 'mdt') {
            return true;
        }
        return strncmp($slug, 'mdt_', 4) === 0;
    }

    private static function hmacKey(): string
    {
        return defined('NEXIUM_API_HMAC_KEY') ? (string) NEXIUM_API_HMAC_KEY : '';
    }

    // =================================================================
    //   Cache loader
    // =================================================================

    private static function loadCache(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = ?");
            $stmt->execute(['licence_products_cache']);
            $raw = $stmt->fetchColumn();

            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    self::$cache = array_merge([
                        'license_domain'              => '',
                        'license_domain_activated_at' => '',
                    ], $decoded);
                    return self::$cache;
                }
            }
        } catch (Throwable $e) {
            error_log('License::loadCache failed: ' . $e->getMessage());
        }

        self::$cache = [
            'products'                 => [],
            'fetched_at'               => 0,
            'license_status'           => 'unknown',
            'license_key'              => '',
            'license_domain'           => '',
            'license_domain_activated_at' => '',
        ];
        return self::$cache;
    }

    private static function persistCache(array $cache): void
    {
        self::$cache = $cache;
        try {
            $db = Database::getInstance()->getConnection();
            $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $db->prepare("
                INSERT INTO admin_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute(['licence_products_cache', $json]);
            $db->prepare("
                INSERT INTO admin_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute(['licence_last_check', date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            error_log('License::persistCache failed: ' . $e->getMessage());
        }
    }

    public static function getMasterKey(): string
    {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = ?");
            $stmt->execute(['licence_master_key']);
            return trim((string) $stmt->fetchColumn());
        } catch (Throwable $e) {
            return '';
        }
    }

    // =================================================================
    //   Public API
    // =================================================================

    /**
     * Force un refresh du statut depuis l'API. Retourne le cache mis a jour.
     *
     * @return array{products:array,fetched_at:int,license_status:string,license_key?:string,last_error?:string|null}
     */
    public static function refresh(?string $licenseKey = null): array
    {
        $key = $licenseKey ?: self::getMasterKey();
        if ($key === '') {
            $cache = [
                'products'                    => [],
                'fetched_at'                  => time(),
                'license_status'              => 'no_key',
                'license_key'                 => '',
                'license_domain'              => '',
                'license_domain_activated_at' => '',
            ];
            self::persistCache($cache);
            return $cache;
        }

        $domain = self::installationHost();
        $url = self::API_BASE . '/api/v1/license/status.php?key=' . urlencode($key)
            . '&domain=' . urlencode($domain);
        $response = self::httpGet($url);

        $cache = [
            'license_key'                 => $key,
            'fetched_at'                  => time(),
            'license_status'              => 'unknown',
            'products'                    => [],
            'last_error'                  => null,
            'license_domain'              => '',
            'license_domain_activated_at' => '',
        ];

        if ($response === null) {
            $cache['license_status'] = 'unreachable';
            $cache['last_error']     = 'API injoignable';
            self::persistCache($cache);
            return $cache;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            $cache['license_status'] = 'invalid_response';
            $cache['last_error']     = 'Reponse API invalide';
            self::persistCache($cache);
            return $cache;
        }

        if (self::hmacKey() === '') {
            $cache['license_status'] = 'misconfigured_hmac';
            $cache['last_error']     = 'NEXIUM_API_HMAC_KEY manquant : impossible de verifier l\'authenticite des reponses API';
            self::persistCache($cache);
            return $cache;
        }

        if (!self::verifyStatusSignature($data)) {
            $cache['license_status'] = 'invalid_signature';
            $cache['last_error']     = 'Signature HMAC invalide (cle MDT doit correspondre a API_SECRET_KEY cote API)';
            self::persistCache($cache);
            return $cache;
        }

        if (($data['status'] ?? '') !== 'OK') {
            $cache['license_status'] = $data['status'] ?? 'error';
            $cache['last_error']     = $data['message'] ?? 'Cle invalide';
            if (($data['status'] ?? '') === 'DOMAIN_MISMATCH') {
                $bound = (string) ($data['license']['domain'] ?? '');
                $cache['last_error'] = $bound !== ''
                    ? ('Cette licence est deja liee au domaine « ' . $bound . ' ». Le site actuel est « ' . self::installationHost() . ' ».')
                    : ($cache['last_error']);
            }
            if (($data['status'] ?? '') === 'MISSING_DOMAIN') {
                $cache['last_error'] = 'API : parametre domain manquant (mise a jour MDT requise).';
            }
            $cache['license_domain']              = (string) ($data['license']['domain'] ?? '');
            $cache['license_domain_activated_at'] = (string) ($data['license']['activated_at'] ?? '');
            self::persistCache($cache);
            return $cache;
        }

        $cache['license_status'] = $data['license']['status'] ?? 'active';
        $cache['products']       = $data['products'] ?? [];
        $cache['license_domain'] = (string) ($data['license']['domain'] ?? '');
        $cache['license_domain_activated_at'] = (string) ($data['license']['activated_at'] ?? '');
        $cache['last_error']     = null;
        self::persistCache($cache);
        return $cache;
    }

    /**
     * Verifie la signature sur la charge utile (sans le champ signature), comme cote API.
     */
    private static function verifyStatusSignature(array $data): bool
    {
        $sig = $data['signature'] ?? '';
        if ($sig === '' || !is_string($sig)) {
            return false;
        }
        $copy = $data;
        unset($copy['signature']);
        $expected = hash_hmac(
            'sha256',
            json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            self::hmacKey()
        );
        return hash_equals($expected, $sig);
    }

    /** Retourne le cache, en le rafraichissant si TTL depasse. */
    public static function getStatus(bool $force = false): array
    {
        $cache = self::loadCache();
        if ($force || (time() - (int)($cache['fetched_at'] ?? 0)) > self::CACHE_TTL) {
            $cache = self::refresh();
        }
        return $cache;
    }

    /** Vrai si le produit indique par son slug est actif (cache). */
    public static function isProductActive(string $slug): bool
    {
        $status = self::getStatus();
        $ls     = $status['license_status'] ?? '';
        if (in_array($ls, ['invalid_signature', 'misconfigured_hmac', 'DOMAIN_MISMATCH', 'MISSING_DOMAIN'], true)) {
            return false;
        }
        if ($ls === 'unreachable') {
            $age = time() - (int)($status['fetched_at'] ?? 0);
            if ($age > 86400) {
                return false;
            }
        }
        foreach ($status['products'] ?? [] as $p) {
            if (($p['slug'] ?? '') === $slug) {
                return ($p['status'] ?? '') === 'active';
            }
        }
        return false;
    }

    /**
     * L'utilisateur a-t-il une ligne MDT "core" active (mdt_1month, mdt_lifetime, etc.) ?
     */
    public static function isMDTActive(): bool
    {
        $status = self::getStatus();
        $ls     = $status['license_status'] ?? '';
        if (in_array($ls, ['invalid_signature', 'misconfigured_hmac', 'DOMAIN_MISMATCH', 'MISSING_DOMAIN'], true)) {
            return false;
        }
        if ($ls === 'unreachable') {
            $age = time() - (int)($status['fetched_at'] ?? 0);
            if ($age > 86400) {
                return false;
            }
        }
        foreach ($status['products'] ?? [] as $p) {
            if (($p['status'] ?? '') !== 'active') {
                continue;
            }
            if (self::isMdtCoreProductSlug((string)($p['slug'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Slug du premier produit MDT actif, pour /heartbeat et /validate (ex. mdt_1month).
     */
    public static function getActiveMdtProductSlug(): string
    {
        foreach (self::getStatus()['products'] ?? [] as $p) {
            if (($p['status'] ?? '') !== 'active') {
                continue;
            }
            if (self::isMdtCoreProductSlug((string)($p['slug'] ?? ''))) {
                return (string) $p['slug'];
            }
        }
        return '';
    }

    /** Date d'expiration ISO du produit ou null. */
    public static function productExpiresAt(string $slug): ?string
    {
        foreach (self::getStatus()['products'] ?? [] as $p) {
            if (($p['slug'] ?? '') === $slug) {
                return $p['expires_at'] ?? null;
            }
        }
        return null;
    }

    /** Indique si le slug est un addon dependant d'une licence MDT (Hack, Mail, Dispatch). */
    public static function isMdtDependentAddon(string $slug): bool
    {
        return in_array($slug, self::MDT_DEPENDENT_ADDONS, true);
    }

    /** Heartbeat : ping sur le produit MDT actuellement actif. */
    public static function heartbeat(): array
    {
        $key = self::getMasterKey();
        if ($key === '') {
            return ['ok' => false, 'error' => 'no_key'];
        }
        $slug = self::getActiveMdtProductSlug();
        if ($slug === '') {
            $slug = self::getActiveMdtProductSlugAfterRefresh();
        }
        if ($slug === '') {
            return ['ok' => false, 'error' => 'no_mdt_product'];
        }
        $host = self::installationHost();
        $payload = [
            'license_key'           => $key,
            'product'               => $slug,
            'binding'               => $_SERVER['HTTP_HOST'] ?? gethostname(),
            'installation_domain'   => $host,
        ];
        $resp = self::httpPost(self::API_BASE . '/api/v1/license/heartbeat.php', $payload);
        if ($resp === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $data = json_decode($resp['body'], true);
        return ['ok' => ($data['status'] ?? '') === 'OK', 'data' => $data];
    }

    private static function getActiveMdtProductSlugAfterRefresh(): string
    {
        self::refresh();
        return self::getActiveMdtProductSlug();
    }

    /**
     * Hote du MDT tel qu'envoye a l'API (HTTP_HOST / SERVER_NAME), normalise.
     */
    public static function installationHost(): string
    {
        $raw = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';

        return self::normalizeInstallationDomain((string) $raw);
    }

    private static function normalizeInstallationDomain(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = strtolower($value);
        $value = preg_replace('#^https?://#', '', $value);
        $value = preg_replace('#^www\.#', '', $value);
        $parts = explode('/', $value, 2);
        $value = $parts[0];
        $parts = explode(':', $value, 2);
        $value = $parts[0];

        return $value;
    }

    // =================================================================
    //   HTTP helpers
    // =================================================================

    private static function httpGet(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'NexiumMDT/1.0 (' . ($_SERVER['HTTP_HOST'] ?? '') . ')',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            error_log('License HTTP GET error: ' . $err);
            return null;
        }
        return ['body' => $body, 'code' => (int) $code];
    }

    private static function httpPost(string $url, array $payload): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_USERAGENT      => 'NexiumMDT/1.0 (' . ($_SERVER['HTTP_HOST'] ?? '') . ')',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            return null;
        }
        return ['body' => $body, 'code' => (int) $code];
    }
}
