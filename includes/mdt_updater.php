<?php
/**
 * Client mises à jour MDT ↔ site Nexium Studio (API /api/mdt).
 */
declare(strict_types=1);

final class MdtUpdater
{
    private const SETTING_VERSION = 'mdt_code_version';
    private const BACKUP_ZIP      = '.mdt_code_backup.zip';

    public static function getInstalledVersion(): string
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = ?');
            $st->execute([self::SETTING_VERSION]);
            $v = trim((string) $st->fetchColumn());
            if ($v !== '') {
                return $v;
            }
        } catch (Throwable $e) {
        }
        return '0.0.0';
    }

    public static function setInstalledVersion(string $version): void
    {
        $db = Database::getInstance()->getConnection();
        $db->prepare('
            INSERT INTO admin_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ')->execute([self::SETTING_VERSION, $version]);
    }

    /**
     * @return array{0:?array,1:?string} [payload, error]
     */
    public static function fetchCheckPayload(bool $includeBeta = false): array
    {
        $key = License::getMasterKey();
        if ($key === '') {
            return [null, 'Définissez la clé de licence master dans Paramètres → Général.'];
        }
        $base = rtrim(NEXIUM_UPDATES_API_BASE, '/');
        $q = http_build_query([
            'current_version' => self::getInstalledVersion(),
            'license_key'     => $key,
            'product'         => 'mdt',
            'include_beta'    => $includeBeta ? '1' : '0',
        ]);
        [$code, $body] = self::httpGet($base . '/api/mdt/check-updates.php?' . $q);
        $j = json_decode($body, true);
        if ($code !== 200) {
            $detail = is_array($j) ? (string) ($j['error'] ?? '') : '';
            $suffix = $detail !== '' ? ' — ' . $detail : '';
            return [null, 'API indisponible (HTTP ' . $code . ').' . $suffix];
        }
        if (!is_array($j) || empty($j['ok'])) {
            $err = is_array($j) ? (string) ($j['error'] ?? 'Réponse invalide') : 'JSON invalide';
            return [null, $err];
        }
        return [$j, null];
    }

    /**
     * @return array{0:bool,1:string}
     */
    public static function installLatestFromApi(): array
    {
        [$info, $err] = self::fetchCheckPayload(false);
        if ($err !== null) {
            return [false, $err];
        }
        if (empty($info['update_available'])) {
            return [false, 'Aucune mise à jour disponible.'];
        }
        $latest = $info['latest'] ?? null;
        if (!is_array($latest) || empty($latest['version'])) {
            return [false, 'Réponse API incomplète.'];
        }
        $version = (string) $latest['version'];
        $hash    = trim((string) ($latest['file_hash'] ?? ''));
        $url     = trim((string) ($info['download_url'] ?? ''));
        if ($url === '') {
            return [false, 'URL de téléchargement manquante.'];
        }

        $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mdt-upd-' . bin2hex(random_bytes(8)) . '.zip';
        [$okDl, $msgDl] = self::downloadFile($url, $tmpZip);
        if (!$okDl) {
            @unlink($tmpZip);
            return [false, $msgDl];
        }

        if ($hash !== '') {
            $fh = hash_file('sha256', $tmpZip);
            if (!is_string($fh) || !hash_equals(strtolower($hash), strtolower($fh))) {
                @unlink($tmpZip);
                return [false, 'Somme SHA-256 incorrecte (fichier corrompu ou altéré).'];
            }
        }

        $minPhp = (string) ($latest['min_php_version'] ?? '8.0');
        if (version_compare(PHP_VERSION, $minPhp, '<')) {
            @unlink($tmpZip);
            return [false, 'PHP ' . $minPhp . ' minimum requis (actuel : ' . PHP_VERSION . ').'];
        }

        [$okApply, $msgApply] = self::applyZipToRoot($tmpZip);
        @unlink($tmpZip);
        if (!$okApply) {
            return [false, $msgApply];
        }

        self::setInstalledVersion($version);
        return [true, 'Mise à jour vers v' . $version . ' terminée. Pensez à vérifier le site et à exécuter les migrations SQL si la release le demande.'];
    }

    /**
     * @return array{0:bool,1:string}
     */
    private static function applyZipToRoot(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            return [false, 'Extension PHP ZipArchive requise.'];
        }
        $root = rtrim(ROOT_PATH, '/\\');
        $zip  = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [false, 'Archive ZIP illisible.'];
        }

        $extractRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mdt-extract-' . bin2hex(random_bytes(6));
        if (!mkdir($extractRoot, 0700, true) && !is_dir($extractRoot)) {
            $zip->close();
            return [false, 'Impossible de créer un dossier temporaire.'];
        }
        if (!$zip->extractTo($extractRoot)) {
            $zip->close();
            self::rrmdir($extractRoot);
            return [false, 'Échec extraction de l’archive.'];
        }
        $zip->close();

        $appRoot = self::findInstallRoot($extractRoot);
        if ($appRoot === null) {
            self::rrmdir($extractRoot);
            return [false, 'Structure d’archive invalide (install.php introuvable).'];
        }

        self::backupCodeTree($root);

        try {
            self::mirrorTree($appRoot, $root);
        } catch (Throwable $e) {
            self::rrmdir($extractRoot);
            return [false, 'Copie des fichiers interrompue : ' . $e->getMessage() . ' — restaurez le ZIP ' . self::BACKUP_ZIP . ' si besoin.'];
        }
        self::rrmdir($extractRoot);
        return [true, 'ok'];
    }

    private static function findInstallRoot(string $dir): ?string
    {
        if (is_file($dir . DIRECTORY_SEPARATOR . 'install.php')) {
            return $dir;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $sub = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($sub) && is_file($sub . DIRECTORY_SEPARATOR . 'install.php')) {
                return $sub;
            }
        }
        return null;
    }

    private static function backupCodeTree(string $root): void
    {
        if (!class_exists('ZipArchive')) {
            return;
        }
        $dest = $root . DIRECTORY_SEPARATOR . self::BACKUP_ZIP;
        $zip = new ZipArchive();
        if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return;
        }
        $prefixes = ['includes', 'pages', 'classes', 'config', 'auth', 'assets'];
        foreach ($prefixes as $pref) {
            $abs = $root . DIRECTORY_SEPARATOR . $pref;
            if (!is_dir($abs)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $fileInfo) {
                /** @var SplFileInfo $fileInfo */
                $path = $fileInfo->getPathname();
                $rel  = ltrim(str_replace(['\\', "\0"], ['/', ''], substr($path, strlen($root))), '/');
                if ($fileInfo->isDir()) {
                    continue;
                }
                if (self::isProtectedRelativePath($rel)) {
                    continue;
                }
                $zip->addFile($path, $rel);
            }
        }
        foreach (['install.php', 'login.php', 'logout.php', 'index.php'] as $fn) {
            $p = $root . DIRECTORY_SEPARATOR . $fn;
            if (is_file($p)) {
                $zip->addFile($p, $fn);
            }
        }
        $zip->close();
    }

    /**
     * @throws RuntimeException
     */
    private static function mirrorTree(string $from, string $to): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            $src = $fileInfo->getPathname();
            $rel = ltrim(str_replace(['\\', "\0"], ['/', ''], substr($src, strlen($from))), '/');
            if (self::isProtectedRelativePath($rel)) {
                continue;
            }
            if (self::isSkippedReleasePath($rel)) {
                continue;
            }
            $dst = $to . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($fileInfo->isDir()) {
                if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
                    throw new RuntimeException('mkdir ' . $dst);
                }
                continue;
            }
            $dir = dirname($dst);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('mkdir ' . $dir);
            }
            if (!copy($src, $dst)) {
                throw new RuntimeException('copy ' . $rel);
            }
        }
    }

    private static function isProtectedRelativePath(string $rel): bool
    {
        $r = strtolower(str_replace('\\', '/', $rel));
        return $r === 'config/config.local.php'
            || $r === 'config/database.local.php'
            || str_starts_with($r, 'config/config.local.php')
            || str_starts_with($r, 'config/database.local.php');
    }

    private static function isSkippedReleasePath(string $rel): bool
    {
        $r = strtolower(str_replace('\\', '/', $rel));
        if (str_starts_with($r, '.git/') || str_starts_with($r, '.cursor/')) {
            return true;
        }
        if (str_starts_with($r, 'node_modules/') || str_starts_with($r, 'dist/')) {
            return true;
        }
        if ($r === 'error_log.txt' || $r === 'installed.lock') {
            return true;
        }
        if (str_starts_with($r, 'assets/img_map/')) {
            return true;
        }
        return false;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $fileInfo) {
            $p = $fileInfo->getPathname();
            if ($fileInfo->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    /**
     * @return array{0:int,1:string}
     */
    private static function httpGet(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_SSL_VERIFYPEER => NEXIUM_UPDATES_VERIFY_SSL,
                CURLOPT_SSL_VERIFYHOST => NEXIUM_UPDATES_VERIFY_SSL ? 2 : 0,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$code, is_string($body) ? $body : ''];
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => 120, 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => NEXIUM_UPDATES_VERIFY_SSL, 'verify_peer_name' => NEXIUM_UPDATES_VERIFY_SSL],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        return [$code, is_string($body) ? $body : ''];
    }

    /**
     * @return array{0:bool,1:string}
     */
    private static function downloadFile(string $url, string $destPath): array
    {
        if (function_exists('curl_init')) {
            $fp = fopen($destPath, 'wb');
            if ($fp === false) {
                return [false, 'Écriture impossible : ' . $destPath];
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT        => 600,
                CURLOPT_SSL_VERIFYPEER => NEXIUM_UPDATES_VERIFY_SSL,
                CURLOPT_SSL_VERIFYHOST => NEXIUM_UPDATES_VERIFY_SSL ? 2 : 0,
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            if (!$ok || $code !== 200) {
                return [false, 'Téléchargement échoué (HTTP ' . $code . ').'];
            }
            return [true, 'ok'];
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => 600],
            'ssl'  => ['verify_peer' => NEXIUM_UPDATES_VERIFY_SSL, 'verify_peer_name' => NEXIUM_UPDATES_VERIFY_SSL],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if (!is_string($data) || $data === '') {
            return [false, 'Téléchargement échoué.'];
        }
        if (file_put_contents($destPath, $data) === false) {
            return [false, 'Écriture impossible.'];
        }
        return [true, 'ok'];
    }
}
