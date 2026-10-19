<?php

declare(strict_types=1);

namespace EzIT\LicenseServer;

use PDO;

/**
 * Remote "log in to a client site" plumbing.
 *
 * EzIT Core (>= the version shipping EzIT_Sso) exposes two REST routes on
 * every licensed site:
 *
 *   GET  {site}/wp-json/ezit/v1/sso/users?key={api_key}&nonce=&sig=
 *   POST {site}/wp-json/ezit/v1/sso/token           key=…&action=…&…
 *
 * Auth: HMAC-SHA256 of "{nonce}" with the product api_key — replay-guarded
 * by a nonce timestamp the client rejects after ~5 minutes.
 *
 * `token` returns a one-time login URL ({site}/wp-login.php?ezit_sso_uid=N
 * &ezit_sso_token=…) that mints a WP session on first use (2-minute TTL,
 * consumed on use — the DreamHost panel-login pattern, generalized).
 *
 * Three login paths:
 *   first_admin  — token for the site's first administrator account
 *   user         — token for an arbitrary user id ("log in as" — pick from
 *                  the list `users` returns; test roles/permissions)
 *   credentials  — username+password, verified by wp_authenticate on the
 *                  site; remembered passwords are stored encrypted here
 *                  (seal()/open(), AES-256-GCM keyed by server_secret)
 *   provision    — create (or reset) a WP user with the given role, then
 *                  token. Today only 'administrator' is sent; the param
 *                  exists so a future "support" role plugs in untouched.
 */
final class SiteLogin
{
    private const ROUTE_USERS = '/wp-json/ezit/v1/sso/users';
    private const ROUTE_TOKEN = '/wp-json/ezit/v1/sso/token';
    private const TIMEOUT     = 15; // blocking — we need the URL back

    /* ── credential vault ─────────────────────────────────────────── */

    private static function key(string $secret): string
    {
        return hash('sha256', 'ezit.site_logins.' . $secret, true);
    }

    /** Encrypt a password for site_logins.password_enc. */
    public static function seal(string $secret, string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', self::key($secret), OPENSSL_RAW_DATA, $iv, $tag);

        return 'gcm:' . base64_encode($iv . $tag . (string) $ct);
    }

    /** Decrypt a stored password. Returns null on tamper/legacy rows. */
    public static function open(string $secret, string $sealed): ?string
    {
        if (! str_starts_with($sealed, 'gcm:')) {
            return null;
        }
        $raw = base64_decode(substr($sealed, 4), true);
        if ($raw === false || strlen($raw) <= 28) {
            return null;
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);

        $plain = openssl_decrypt($ct, 'aes-256-gcm', self::key($secret), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    /* ── site calls ───────────────────────────────────────────────── */

    /**
     * Resolve an activation to the site target. Returns
     * [site_url, api_key] or null when the activation/site is missing.
     */
    public static function target(PDO $db, int $activationId): ?array
    {
        $stmt = $db->prepare(
            'SELECT a.site_url, a.domain, p.api_key, p.name AS product_name
             FROM activations a
             JOIN licenses l ON l.id = a.license_id
             JOIN products p ON p.id = l.product_id
             WHERE a.id = ?'
        );
        $stmt->execute([$activationId]);
        $row = $stmt->fetch();
        if (! $row || empty($row['site_url'])) {
            return null;
        }
        $site = rtrim((string) $row['site_url'], '/');
        $scheme = strtolower((string) parse_url($site, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null; // never POST keys to exotic schemes
        }

        return [$site, (string) $row['api_key'], (string) ($row['domain'] ?? '')];
    }

    /**
     * GET sso/users — list the site's users for the "Log in as" picker.
     *
     * @return array{users: array<int,array{id:int,login:string,display:string,roles:array<int,string>}}|null,error?:string}
     */
    public static function users(string $site, string $apiKey): array
    {
        $nonce = (string) time() . '.' . bin2hex(random_bytes(8));
        $sig   = hash_hmac('sha256', $nonce, $apiKey);
        $url   = $site . self::ROUTE_USERS . '?' . http_build_query([
            'key'   => $apiKey,
            'nonce' => $nonce,
            'sig'   => $sig,
        ]);

        $res = self::request('GET', $url, null);
        if ($res['error'] !== null) {
            return ['users' => null, 'error' => $res['error']];
        }
        $data = json_decode((string) $res['body'], true);
        if (! is_array($data) || ! isset($data['users']) || ! is_array($data['users'])) {
            return ['users' => null, 'error' => 'Unexpected response from site.'];
        }

        return ['users' => $data['users'], 'error' => null];
    }

    /**
     * POST sso/token — ask the site for a one-time login URL.
     *
     * @param array $params action + fields: user_id / username+password /
     *                      username+password+email+role (provision)
     * @return array{login_url:?string, error:?string}
     */
    public static function token(string $site, string $apiKey, array $params): array
    {
        $nonce = (string) time() . '.' . bin2hex(random_bytes(8));
        $body  = array_merge($params, [
            'key'   => $apiKey,
            'nonce' => $nonce,
            'sig'   => hash_hmac('sha256', $nonce, $apiKey),
        ]);

        $res = self::request('POST', $site . self::ROUTE_TOKEN, http_build_query($body));
        if ($res['error'] !== null) {
            return ['login_url' => null, 'error' => $res['error']];
        }
        $data = json_decode((string) $res['body'], true);
        if (! is_array($data)) {
            return ['login_url' => null, 'error' => 'Unexpected response from site.'];
        }
        if (! empty($data['error'])) {
            return ['login_url' => null, 'error' => (string) $data['error']];
        }
        if (empty($data['login_url'])) {
            return ['login_url' => null, 'error' => 'Site did not return a login URL (update EzIT Core?).'];
        }

        return ['login_url' => (string) $data['login_url'], 'error' => null];
    }

    /**
     * Capability probe — does this site run EzIT Core >=1.3.0? An
     * unsigned GET on sso/users: the route only exists when the SSO
     * module is present, so a genuine 404 means "old plugin" while
     * 401/403/200 mean the endpoint is live (auth correctly demanded).
     * status 0 (unreachable) also reports false — the button would be
     * dead either way.
     */
    public static function capable(string $site): bool
    {
        $url = $site . self::ROUTE_USERS;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'EzIT-LicenseServer/1.0',
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http'  => ['method' => 'GET', 'timeout' => 4, 'ignore_errors' => true],
                'ssl'   => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            $status = $body !== false
                ? (int) (explode(' ', $http_response_header[0] ?? '0')[1] ?? 0)
                : 0;
        }

        return $status !== 0 && $status !== 404;
    }

    /* ── transport ──────────────────────────────────────────────── */

    /**
     * @return array{body:?string, error:?string}
     */
    private static function request(string $method, string $url, ?string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false, // never re-send keys to a redirect target
                CURLOPT_USERAGENT      => 'EzIT-LicenseServer/1.2',
            ];
            if ($method === 'POST') {
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = (string) $body;
            }
            curl_setopt_array($ch, $opts);
            $out  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($out === false) {
                return ['body' => null, 'error' => 'Site unreachable (' . $err . ')'];
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'          => $method,
                    'header'          => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content'         => (string) $body,
                    'timeout'         => self::TIMEOUT,
                    'follow_location' => 0,
                    'max_redirects'   => 1,
                ],
            ]);
            $out  = @file_get_contents($url, false, $ctx);
            $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
            if ($out === false) {
                return ['body' => null, 'error' => 'Site unreachable.'];
            }
        }

        if ($code === 403) {
            return ['body' => null, 'error' => 'Site refused the request (bad api_key or missing EzIT_Sso — update EzIT Core on that site).'];
        }
        if ($code >= 400) {
            $data = json_decode((string) $out, true);
            return ['body' => null, 'error' => 'Site error HTTP ' . $code . (is_array($data) && ! empty($data['error']) ? ': ' . $data['error'] : '')];
        }

        return ['body' => (string) $out, 'error' => null];
    }
}
