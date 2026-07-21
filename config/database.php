<?php
/**
 * Configuration de la base de données — MDT
 * Priorité : database.local.php (généré par install.php) > valeurs par défaut ci-dessous.
 */
if (is_readable(__DIR__ . '/database.local.php')) {
    require_once __DIR__ . '/database.local.php';
}

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'mdt_service_public');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'mdt_service_public_user');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

class Database {
    private static $instance = null;
    private static $connectFailed = false;
    private $connection;

    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        $this->syncTimezone();
    }

    /**
     * Aligne PHP et MySQL sur le fuseau horaire défini dans
     * `pages/staff/settings.php` (admin_settings.timezone).
     *
     * Repli silencieux sur MDT_TIMEZONE (ou 'Europe/Paris') si la table
     * n'existe pas encore (installation fraîche) ou si la valeur est invalide.
     * Le fuseau MySQL est positionné via un offset numérique (±HH:MM) pour
     * éviter d'exiger le chargement des tables de fuseaux nommés.
     */
    private function syncTimezone(): void {
        $tz = defined('MDT_TIMEZONE') ? (string) MDT_TIMEZONE : 'Europe/Paris';
        try {
            $st = $this->connection->prepare(
                "SELECT setting_value FROM admin_settings WHERE setting_key = 'timezone' LIMIT 1"
            );
            $st->execute();
            $val = trim((string) ($st->fetchColumn() ?: ''));
            if ($val !== '' && in_array($val, timezone_identifiers_list(), true)) {
                $tz = $val;
            }
        } catch (Throwable $e) {
            // admin_settings absent (installation) ou erreur transitoire : fallback.
        }

        date_default_timezone_set($tz);

        try {
            $offset = (new DateTime('now', new DateTimeZone($tz)))->format('P'); // ex: +02:00
            $this->connection->exec("SET time_zone = '" . $offset . "'");
        } catch (Throwable $e) {
            // Serveur MySQL refuse la session tz : on reste sur le défaut serveur.
        }
    }

    /** Fuseau horaire applicatif courant (après syncTimezone). */
    public static function timezone(): string {
        try { self::getInstance(); } catch (Throwable $e) {}
        return date_default_timezone_get();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            try {
                self::$instance = new self();
            } catch (PDOException $e) {
                self::$connectFailed = true;
                die('Erreur de connexion à la base de données : ' . $e->getMessage());
            }
        }
        return self::$instance;
    }

    /**
     * Connexion si elle est disponible, null sinon — n'interrompt jamais la requête,
     * contrairement à getInstance(). Réservé aux appelants capables de fonctionner
     * en mode dégradé (ex. mdt_roles_registry(), qui se replie sur les rôles connus).
     */
    public static function tryGetConnection(): ?PDO {
        if (self::$instance !== null) {
            return self::$instance->getConnection();
        }
        if (self::$connectFailed) {
            return null;
        }
        try {
            self::$instance = new self();
            return self::$instance->getConnection();
        } catch (Throwable $e) {
            self::$connectFailed = true;
            return null;
        }
    }

    public function getConnection() {
        return $this->connection;
    }

    private function __clone() {}

    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}
