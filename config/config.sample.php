<?php
/**
 * Ez IT Solutions — License Server configuration.
 *
 * Copy this file to config.php and adjust the values.
 * The web installer (install/) can also generate it for you.
 */

return [
    // ── Database ──────────────────────────────────────────────────
    // SQLite (default, zero-config). The file is created automatically.
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/../data/license-server.sqlite',

        // MySQL alternative:
        // 'driver'   => 'mysql',
        // 'host'     => 'localhost',
        // 'port'     => 3306,
        // 'database' => 'ezit_licenses',
        // 'username' => 'ezit_licenses',
        // 'password' => 'change-me',
        // 'charset'  => 'utf8mb4',
    ],

    // ── Security ──────────────────────────────────────────────────
    // Random 64-char hex. Used to sign license certificates (HMAC).
    // Generate: php -r "echo bin2hex(random_bytes(32));"
    'server_secret' => 'CHANGE-ME-GENERATE-A-64-CHAR-HEX-STRING',

    // ── App ───────────────────────────────────────────────────────
    'app_name' => 'Ez IT Solutions License Server',
    'app_url'  => 'https://license.ez-it-solutions.com',

    // Public API rate limit (requests per minute per IP)
    'rate_limit_per_minute' => 60,

    // Planned-outage switch: when true, every /api/* call returns
    // 503 SERVER_UNAVAILABLE instantly (admin panel stays up).
    // Same effect as dropping a file at data/.maintenance (dashboard
    // → Server Config → Maintenance mode toggles that file).
    // 'maintenance' => true,

    // Session cookie name
    'session_name' => 'ezitls',

    // Set to your admin email — used for login alerts (optional)
    'notify_email' => 'chris@ez-it-solutions.com',
];
