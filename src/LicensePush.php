<?php

declare(strict_types=1);

namespace EzIT\LicenseServer;

use PDO;

/**
 * Server → site push. Every site runs EzIT Core, which exposes two
 * endpoints (key = a product api_key):
 *   POST {site}/wp-json/ezit/v1/license-push — immediate re-verify
 *   POST {site}/wp-json/ezit/v1/update-push  — immediate update scan
 *     (forced releases apply within seconds; EzIT Core ≥1.4.2)
 * Calling them whenever a license, activation or forced update changes
 * makes changes propagate in seconds instead of at the site's next cron.
 *
 * Best-effort AND non-blocking: mutation handlers only *queue*
 * targets; json() flushes the HTTP response first, then runs
 * flush() — a slow or dead site can never stall the admin UI.
 * Failures are still logged; the site's cron converges on its own.
 */
final class LicensePush
{
    private const ENDPOINT        = '/wp-json/ezit/v1/license-push';
    private const ENDPOINT_UPDATE = '/wp-json/ezit/v1/update-push';
    private const TIMEOUT  = 5; // seconds per site
    /** Default per-flush cap — override via config['push_max_sites']. */
    private const MAX_SITE = 25;

    /** @var array<int, array{site:string, key:string, license_id:?int, reason:string, path:string}> */
    private static array $queue = [];

    /** Queue every site with an activation on this license. */
    public static function license(PDO $db, int $licenseId, string $reason): void
    {
        $stmt = $db->prepare(
            'SELECT DISTINCT a.site_url, p.api_key
             FROM activations a
             JOIN licenses l ON l.id = a.license_id
             JOIN products p ON p.id = l.product_id
             WHERE a.license_id = ? AND a.site_url IS NOT NULL AND a.site_url != \'\''
        );
        $stmt->execute([$licenseId]);
        foreach ($stmt->fetchAll() as $t) {
            self::$queue[] = ['site' => $t['site_url'], 'key' => $t['api_key'], 'license_id' => $licenseId, 'reason' => $reason, 'path' => self::ENDPOINT];
        }
    }

    /** Queue every site with an activation on this license's product
     *  (used when a product is disabled — every license under it
     *  becomes unverifiable). */
    public static function product(PDO $db, int $productDbId, string $reason): void
    {
        $stmt = $db->prepare(
            'SELECT DISTINCT a.site_url, p.api_key, a.license_id
             FROM activations a
             JOIN licenses l ON l.id = a.license_id
             JOIN products p ON p.id = l.product_id
             WHERE l.product_id = ? AND a.site_url IS NOT NULL AND a.site_url != \'\''
        );
        $stmt->execute([$productDbId]);
        foreach ($stmt->fetchAll() as $t) {
            self::$queue[] = ['site' => $t['site_url'], 'key' => $t['api_key'], 'license_id' => (int) $t['license_id'], 'reason' => $reason, 'path' => self::ENDPOINT];
        }
        self::registry($db, $productDbId, $reason);
    }

    /** Queue every site recorded in product_sites — sites that call
     *  check_update but never activated a license (free catalog items,
     *  the self product EzIT Core). Update pushes depend on this. */
    public static function registry(PDO $db, int $productDbId, string $reason): void
    {
        $stmt = $db->prepare(
            'SELECT DISTINCT ps.site_url, p.api_key
             FROM product_sites ps
             JOIN products p ON p.id = ps.product_id
             WHERE ps.product_id = ? AND ps.site_url != \'\''
        );
        $stmt->execute([$productDbId]);
        foreach ($stmt->fetchAll() as $t) {
            self::$queue[] = ['site' => $t['site_url'], 'key' => $t['api_key'], 'license_id' => null, 'reason' => $reason, 'path' => self::ENDPOINT];
        }
    }

    /** Queue an update-push for every known site on this product —
     *  activations AND the product_sites registry — when a forced
     *  release goes live. $targets (normalized domains) limits the
     *  fan-out for a targeted rollout; null = every known site. */
    public static function update(PDO $db, int $productDbId, string $reason, ?array $targets = null): void
    {
        $wanted = $targets === null ? null
            : array_fill_keys(array_map('strval', $targets), true);
        $hit = static fn (string $siteUrl): bool =>
            $wanted === null
            || isset($wanted[Database::normalizeDomain($siteUrl)]);

        $stmt = $db->prepare(
            'SELECT DISTINCT a.site_url, p.api_key, a.license_id
             FROM activations a
             JOIN licenses l ON l.id = a.license_id
             JOIN products p ON p.id = l.product_id
             WHERE l.product_id = ? AND a.site_url IS NOT NULL AND a.site_url != \'\''
        );
        $stmt->execute([$productDbId]);
        foreach ($stmt->fetchAll() as $t) {
            if ($hit((string) $t['site_url'])) {
                self::$queue[] = ['site' => $t['site_url'], 'key' => $t['api_key'], 'license_id' => (int) $t['license_id'], 'reason' => $reason, 'path' => self::ENDPOINT_UPDATE];
            }
        }

        $stmt = $db->prepare(
            'SELECT DISTINCT ps.site_url, p.api_key
             FROM product_sites ps
             JOIN products p ON p.id = ps.product_id
             WHERE ps.product_id = ? AND ps.site_url != \'\''
        );
        $stmt->execute([$productDbId]);
        foreach ($stmt->fetchAll() as $t) {
            if ($hit((string) $t['site_url'])) {
                self::$queue[] = ['site' => $t['site_url'], 'key' => $t['api_key'], 'license_id' => null, 'reason' => $reason, 'path' => self::ENDPOINT_UPDATE];
            }
        }
    }

    /** Queue every known activated site — for global events like
     *  maintenance mode, where every site must re-verify now. */
    public static function broadcast(PDO $db, string $reason): void
    {
        $stmt = $db->query(
            'SELECT DISTINCT a.site_url, p.api_key, a.license_id
             FROM activations a
             JOIN licenses l ON l.id = a.license_id
             JOIN products p ON p.id = l.product_id
             WHERE a.site_url IS NOT NULL AND a.site_url != \'\''
        );
        foreach ($stmt->fetchAll() as $t) {
            self::$queue[] = ['site' => $t['site_url'], 'key' => $t['api_key'], 'license_id' => (int) $t['license_id'], 'reason' => $reason, 'path' => self::ENDPOINT];
        }
    }

    /** Queue a single activation's site (enable/disable/revoke). */
    public static function activation(PDO $db, int $activationId, string $reason): void
    {
        $stmt = $db->prepare(
            'SELECT a.site_url, a.license_id, p.api_key
             FROM activations a
             JOIN licenses l ON l.id = a.license_id
             JOIN products p ON p.id = l.product_id
             WHERE a.id = ?'
        );
        $stmt->execute([$activationId]);
        $row = $stmt->fetch();

        if ($row && $row['site_url']) {
            self::$queue[] = ['site' => $row['site_url'], 'key' => $row['api_key'], 'license_id' => (int) $row['license_id'], 'reason' => $reason, 'path' => self::ENDPOINT];
        }
    }

    /**
     * Send queued pushes. Call AFTER the HTTP response has been
     * flushed (see json() in AdminApi/LicenseApi) so request latency
     * never depends on client-site reachability.
     *
     * $maxSites bounds the per-flush fan-out (each send can take up to
     * TIMEOUT seconds). Null falls back to MAX_SITE; pass
     * config['push_max_sites'] to raise it as the fleet grows.
     */
    public static function flush(PDO $db, ?int $maxSites = null): void
    {
        $max  = $maxSites !== null && $maxSites > 0 ? $maxSites : self::MAX_SITE;
        $seen = [];

        foreach (self::$queue as $t) {
            $site = rtrim((string) $t['site'], '/');
            $path = (string) ($t['path'] ?? self::ENDPOINT);
            // Dedupe per site+endpoint — an update push and a license
            // push to the same site are both worth sending.
            $dedupe = $site . '|' . $path;
            if ($site === '' || isset($seen[$dedupe]) || count($seen) >= $max) {
                continue;
            }
            $seen[$dedupe] = true;

            $ok = self::send($site, (string) $t['key'], $path);

            Database::log(
                $db,
                'push',
                $t['license_id'],
                parse_url($site, PHP_URL_HOST) ?: $site,
                null,
                $t['reason'] . ($ok ? '' : ' (site unreachable)'),
                $ok
            );
        }

        self::$queue = [];
    }

    private static function send(string $site, string $apiKey, string $path = self::ENDPOINT): bool
    {
        // site_url is client-declared (LB-URL) — only ever POST a key to
        // plain http/https endpoints. Anything else (file://, gopher://,
        // phar://…) is dropped on the floor.
        $scheme = strtolower((string) parse_url($site, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $url = $site . $path;
        $body = http_build_query(['key' => $apiKey]);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 3,
                // No redirect chasing — a 3xx would re-POST the api_key
                // body to the *redirected* host, which the site owner
                // controls. Moved sites converge via cron instead.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT      => 'EzIT-LicenseServer/1.2',
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $code >= 200 && $code < 300;
        }

        // No cURL — plain stream fallback.
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'         => $body,
                'timeout'         => self::TIMEOUT,
                'follow_location' => 0, // same no-redirect rule as cURL
                'max_redirects'   => 1,
            ],
        ]);

        return @file_get_contents($url, false, $ctx) !== false;
    }
}
