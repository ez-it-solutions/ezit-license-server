<?php

declare(strict_types=1);

/**
 * Ez IT Solutions — License Server front controller.
 *
 * Routing:
 *   /api/*        → LicenseBox-compatible public API (consumed by Botble CMS)
 *   /admin-api/*  → Session-authed JSON API for the dashboard
 *   /install      → First-run installer
 *   /*            → Admin dashboard SPA
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'EzIT\\LicenseServer\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use EzIT\LicenseServer\AdminApi;
use EzIT\LicenseServer\Database;
use EzIT\LicenseServer\LicenseApi;
use EzIT\LicenseServer\LogRetention;

$configFile = __DIR__ . '/config/config.php';
$installed = is_file($configFile);
$config = $installed ? require $configFile : null;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// ── Installer ───────────────────────────────────────────────────────
if (! $installed || str_starts_with($uri, '/install')) {
    if ($installed && ! str_starts_with($uri, '/install')) {
        // fall through to app
    } else {
        require __DIR__ . '/install/index.php';
        exit;
    }
}

// ── Planned maintenance — before ANY DB work: the flag file exists so
//    sites get a fast 503 (not a hang) even when the DB itself is the
//    thing under maintenance. LicenseApi re-checks for good measure.
if (str_starts_with($uri, '/api/')
    && $installed
    && (is_file(__DIR__ . '/data/.maintenance') || ! empty($config['maintenance']))) {
    header('Retry-After: ' . max(30, (int) ($config['maintenance_retry_after'] ?? 300)));
    header('Content-Type: application/json');
    http_response_code(503);
    echo json_encode([
        'status'      => false,
        'status_code' => 'SERVER_UNAVAILABLE',
        'message'     => 'License server is temporarily unavailable for maintenance.',
    ]);
    exit;
}

// ── Session (admin panel only) ──────────────────────────────────────
if (str_starts_with($uri, '/admin-api') || $uri === '/' || ! str_starts_with($uri, '/api')) {
    session_name($config['session_name'] ?? 'ezitls');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
    ]);
    session_start();
}

$pdo = Database::connect($config);
Database::migrate($pdo);

// Log retention — runs at most once/day (file-flag). A purge failure
// must never break a request, so it's swallowed here.
try {
    LogRetention::maybeRun($pdo, __DIR__ . '/data');
} catch (Throwable) {
    // next request retries
}

// ── Routes ──────────────────────────────────────────────────────────
if (str_starts_with($uri, '/api/')) {
    header('X-Content-Type-Options: nosniff');
    (new LicenseApi($pdo, $config))->handle($method, substr($uri, 5));
}

if (str_starts_with($uri, '/admin-api/')) {
    header('X-Content-Type-Options: nosniff');
    (new AdminApi($pdo, $config))->handle($method, substr($uri, 11));
}

if (str_starts_with($uri, '/assets/')) {
    serveAsset($uri);
}

// Admin SPA
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
require __DIR__ . '/views/admin.php';
exit;

function serveAsset(string $uri): void
{
    $base = realpath(__DIR__ . '/assets');
    $path = realpath(__DIR__ . $uri);

    if (! $path || ! str_starts_with($path, $base) || ! is_file($path)) {
        http_response_code(404);
        exit;
    }

    $types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'woff2' => 'font/woff2'];
    header('Content-Type: ' . ($types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}
