<?php

declare(strict_types=1);

namespace EzIT\LicenseServer;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $config): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $db = $config['db'];

        if (($db['driver'] ?? 'sqlite') === 'sqlite') {
            $path = $db['sqlite_path'];
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            self::$pdo = new PDO('sqlite:' . $path, null, null, self::options());
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'],
                $db['port'] ?? 3306,
                $db['database'],
                $db['charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO($dsn, $db['username'], $db['password'], self::options());
        }

        return self::$pdo;
    }

    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    public static function installed(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM admins LIMIT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admins (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id     VARCHAR(64) NOT NULL UNIQUE,   -- e.g. 5F061D22 (client core.json productId)
    name           VARCHAR(190) NOT NULL,
    api_key        VARCHAR(64) NOT NULL UNIQUE,   -- sent by client as LB-API-KEY header
    latest_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
    status         VARCHAR(16) NOT NULL DEFAULT 'enabled', -- enabled|disabled — disabled rejects ALL API calls
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS licenses (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id      INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    code            VARCHAR(64) NOT NULL UNIQUE,
    client_name     VARCHAR(190) NOT NULL,         -- buyer / site owner label
    client_email    VARCHAR(190) DEFAULT NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'active',   -- active|suspended|expired
    max_activations INTEGER NOT NULL DEFAULT 1,
    expires_at      DATETIME DEFAULT NULL,         -- NULL = lifetime
    notes           TEXT DEFAULT NULL,
    features        TEXT DEFAULT NULL,             -- JSON array; issued into the cert for client feature flags
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    license_id    INTEGER NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
    domain        VARCHAR(255) NOT NULL,           -- normalized LB-URL host
    site_url      VARCHAR(255) DEFAULT NULL,       -- full LB-URL
    ip            VARCHAR(64) DEFAULT NULL,        -- LB-IP
    status        VARCHAR(16) NOT NULL DEFAULT 'enabled',  -- enabled|disabled
    activated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_check_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (license_id, domain)
);

CREATE TABLE IF NOT EXISTS license_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    license_id    INTEGER NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
    username      VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(120) DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (license_id, username)
);

CREATE TABLE IF NOT EXISTS updates (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id   INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    update_id    VARCHAR(64) NOT NULL UNIQUE,
    version      VARCHAR(32) NOT NULL,
    summary      TEXT DEFAULT NULL,
    changelog    TEXT DEFAULT NULL,
    has_sql      INTEGER NOT NULL DEFAULT 0,
    file_path    VARCHAR(255) DEFAULT NULL,        -- path to update zip on this server
    file_size    INTEGER NOT NULL DEFAULT 0,
    release_date DATE NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    license_id INTEGER DEFAULT NULL REFERENCES licenses(id) ON DELETE SET NULL,
    action     VARCHAR(40) NOT NULL,               -- activate|verify|deactivate|connect|update_check|download|config_fetch|admin_*
    domain     VARCHAR(255) DEFAULT NULL,
    ip         VARCHAR(64) DEFAULT NULL,
    detail     TEXT DEFAULT NULL,
    success    INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS server_configs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    version      INTEGER NOT NULL UNIQUE,          -- monotonically increasing; clients cache by this
    payload      TEXT NOT NULL,                    -- full JSON config doc served by /api/get_config
    note         TEXT DEFAULT NULL,                -- admin note: why this version was published
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_activity_created ON activity_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_licenses_code ON licenses(code);
CREATE INDEX IF NOT EXISTS idx_activations_domain ON activations(domain);
SQL);

        // Additive migration for databases created before products.status existed
        try {
            $pdo->exec("ALTER TABLE products ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'enabled'");
        } catch (PDOException) {
            // column already exists
        }

        // Same for activations.status
        try {
            $pdo->exec("ALTER TABLE activations ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'enabled'");
        } catch (PDOException) {
            // column already exists
        }

        // Same for licenses.features (JSON feature flags issued into certs)
        try {
            $pdo->exec('ALTER TABLE licenses ADD COLUMN features TEXT DEFAULT NULL');
        } catch (PDOException) {
            // column already exists
        }

        // Seed the bootstrap config (v1) so /api/get_config always has a
        // coherent document before anyone publishes from the admin panel.
        if ((int) $pdo->query('SELECT COUNT(*) FROM server_configs')->fetchColumn() === 0) {
            ServerConfig::publish($pdo, ServerConfig::defaultDoc(), 'Initial bootstrap config');
        }
    }

    public static function log(
        PDO $pdo,
        string $action,
        ?int $licenseId = null,
        ?string $domain = null,
        ?string $ip = null,
        ?string $detail = null,
        bool $success = false
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO activity_logs (license_id, action, domain, ip, detail, success)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$licenseId, $action, $domain, $ip, $detail, $success ? 1 : 0]);
    }

    public static function normalizeDomain(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST) ?: trim($url);

        return strtolower(preg_replace('/^www\./', '', $host));
    }
}
