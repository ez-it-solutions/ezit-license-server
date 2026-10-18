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
    recovery_code_hash   VARCHAR(64) DEFAULT NULL,   -- sha256 of normalized code; raw code is shown once, never stored
    recovery_digest      VARCHAR(64) DEFAULT NULL,   -- HMAC-SHA256('recovery|'.code, api_key) — ships in certs/verify
    recovery_code        VARCHAR(40) DEFAULT NULL,   -- legacy plaintext column, cleared after migration
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

CREATE TABLE IF NOT EXISTS settings (
    name       VARCHAR(64) PRIMARY KEY,
    value      TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS catalog_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id  INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    slug        VARCHAR(120) NOT NULL UNIQUE,    -- URL-safe marketplace slug
    type        VARCHAR(20) NOT NULL DEFAULT 'product', -- theme|plugin|product (manifest contract)
    category    VARCHAR(40) NOT NULL DEFAULT 'generic',
    excerpt     TEXT DEFAULT NULL,               -- card teaser
    body        TEXT DEFAULT NULL,               -- detail-page copy
    thumb       TEXT DEFAULT NULL,               -- card image URL
    screenshots TEXT DEFAULT NULL,               -- JSON array of image URLs
    price       REAL DEFAULT NULL,               -- NULL + is_free → free install
    is_free     INTEGER NOT NULL DEFAULT 0,      -- 1 = api_key exposed in detail (install w/o purchase)
    published   INTEGER NOT NULL DEFAULT 0,
    license_scope TEXT DEFAULT NULL,             -- NULL = public; JSON array of license codes = only those licensees see/install it
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS log_rules (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    action      VARCHAR(64) NOT NULL,            -- activity_logs.action or '*' catch-all
    retain_days INTEGER NOT NULL DEFAULT 90,     -- 0 = keep forever
    keep_last   INTEGER NOT NULL DEFAULT 0,      -- pin newest N rows per action×license×domain
    position    INTEGER NOT NULL DEFAULT 0,      -- rule order (first action-match wins)
    enabled     INTEGER NOT NULL DEFAULT 1
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

        // Same for catalog_items.type (theme|plugin|product badge in the
        // marketplace card — added after the table first shipped)
        try {
            $pdo->exec("ALTER TABLE catalog_items ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'product'");
        } catch (PDOException) {
            // column already exists
        }

        // catalog_items.license_scope — restrict a listing to sites whose
        // domain activated one of the listed license codes.
        try {
            $pdo->exec("ALTER TABLE catalog_items ADD COLUMN license_scope TEXT DEFAULT NULL");
        } catch (PDOException) {
            // column already exists
        }

        // Same for licenses.features (JSON feature flags issued into certs)
        try {
            $pdo->exec('ALTER TABLE licenses ADD COLUMN features TEXT DEFAULT NULL');
        } catch (PDOException) {
            // column already exists
        }

        // Same for products.recovery_code (offline emergency-override code)
        try {
            $pdo->exec('ALTER TABLE products ADD COLUMN recovery_code VARCHAR(40) DEFAULT NULL');
        } catch (PDOException) {
            // column already exists
        }

        // products.recovery_code_hash + recovery_digest — the raw code is
        // shown once at generation, then stored only as hashes.
        try {
            $pdo->exec('ALTER TABLE products ADD COLUMN recovery_code_hash VARCHAR(64) DEFAULT NULL');
        } catch (PDOException) {
            // column already exists
        }
        try {
            $pdo->exec('ALTER TABLE products ADD COLUMN recovery_digest VARCHAR(64) DEFAULT NULL');
        } catch (PDOException) {
            // column already exists
        }
        // Fold legacy plaintext codes into hashed storage, then blank the
        // plaintext column — a DB dump must not yield working codes.
        $legacy = $pdo->query(
            "SELECT id, api_key, recovery_code FROM products
             WHERE recovery_code IS NOT NULL AND recovery_code != ''"
        )->fetchAll();
        $upd = $pdo->prepare(
            'UPDATE products SET recovery_code_hash = ?, recovery_digest = ?, recovery_code = NULL WHERE id = ?'
        );
        foreach ($legacy as $row) {
            $upd->execute([
                self::recoveryCodeHash($row['recovery_code']),
                self::recoveryDigestFor($row['recovery_code'], $row['api_key']),
                $row['id'],
            ]);
        }
        // Any product still lacking a code gets one (hash-only — shown nowhere).
        $missing = $pdo->query('SELECT id, api_key FROM products WHERE recovery_code_hash IS NULL OR recovery_code_hash = \'\'')->fetchAll();
        $upd2 = $pdo->prepare('UPDATE products SET recovery_code_hash = ?, recovery_digest = ? WHERE id = ?');
        foreach ($missing as $row) {
            $code = self::generateRecoveryCode();
            $upd2->execute([self::recoveryCodeHash($code), self::recoveryDigestFor($code, $row['api_key']), $row['id']]);
        }

        // Seed the bootstrap config (v1) so /api/get_config always has a
        // coherent document before anyone publishes from the admin panel.
        if ((int) $pdo->query('SELECT COUNT(*) FROM server_configs')->fetchColumn() === 0) {
            ServerConfig::publish($pdo, ServerConfig::defaultDoc(), 'Initial bootstrap config');
        }

        // Log-retention starter rules (verify/connect purge fast but pin
        // each site's last record; activate/deactivate keep forever).
        LogRetention::seed($pdo);
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

    /**
     * Emergency-override code for a product: 16 base32 chars in 4 groups.
     * Sites never see the raw code — they receive its HMAC digest inside
     * the signed cert / verify response and validate entries locally.
     */
    public static function generateRecoveryCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no I/L/O/0/1
        $code = '';
        for ($i = 0; $i < 16; $i++) {
            $code .= $alphabet[random_int(0, 31)] . (($i % 4 === 3 && $i < 15) ? '-' : '');
        }

        return $code;
    }

    /** Client-side normalization: uppercase + trim before hashing. */
    public static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * At-rest hash of the normalized code — lets the server recognize a
     * code without ever storing it in plaintext. Unsalted sha256 is fine:
     * codes carry ~80 bits of entropy, nothing to brute-force.
     */
    public static function recoveryCodeHash(string $code): string
    {
        return hash('sha256', self::normalizeRecoveryCode($code));
    }

    /**
     * The digest shipped to sites (cert payload + verify responses):
     *   recovery_digest = HMAC-SHA256('recovery|' . UPPERCASE(trim(code)), api_key)
     */
    public static function recoveryDigestFor(string $code, string $apiKey): string
    {
        return hash_hmac('sha256', 'recovery|' . self::normalizeRecoveryCode($code), $apiKey);
    }

    public static function normalizeDomain(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST) ?: trim($url);

        return strtolower(preg_replace('/^www\./', '', $host));
    }
}
