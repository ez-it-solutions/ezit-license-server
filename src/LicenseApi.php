<?php

declare(strict_types=1);

namespace EzIT\LicenseServer;

use PDO;

/**
 * LicenseBox-compatible public API consumed by Botble CMS
 * (platform/core/base/src/Supports/Core.php).
 *
 * All routes are mounted under /api/*.
 */
final class LicenseApi
{
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /** Route a /api/* request. $method = HTTP verb, $path = 'activate_license' etc. */
    public function handle(string $method, string $path): void
    {
        $path = trim($path, '/');

        // Planned outage: answer instantly so client sites fail fast
        // (no 20s timeouts) and can mark the server "maintenance".
        if ($this->maintenanceMode()) {
            header('Retry-After: ' . $this->maintenanceRetryAfter());
            $this->json([
                'status' => false,
                'status_code' => 'SERVER_UNAVAILABLE',
                'message' => 'License server is temporarily unavailable for maintenance.',
            ], 503);
        }

        match (true) {
            $path === 'check_connection_ext' => $this->checkConnection(),
            $path === 'activate_license' => $this->activateLicense(),
            $path === 'access_login' => $this->accessLogin(),
            $path === 'verify_license' => $this->verifyLicense(),
            $path === 'deactivate_license' => $this->deactivateLicense(),
            $path === 'check_update' => $this->checkUpdate(),
            $path === 'versions' => $this->versions(),
            $path === 'get_config' => $this->getConfig(),
            $path === 'catalog' => $this->catalogList(),
            preg_match('#^catalog/([A-Za-z0-9_-]+)$#', $path, $m) === 1 => $this->catalogItem($m[1]),
            preg_match('#^get_update_size/(.+)$#', $path, $m) === 1 => $this->getUpdateSize($m[1]),
            preg_match('#^download_update/main/(.+)$#', $path, $m) === 1 => $this->downloadUpdate($m[1]),
            default => $this->json(['status' => false, 'message' => 'Unknown endpoint.'], 404),
        };
    }

    /* ─────────────────────────── endpoints ─────────────────────────── */

    private function checkConnection(): void
    {
        $product = $this->authenticate();
        $this->log('connect', null, true, 'connection ok');

        $this->json([
            'status' => true,
            'message' => 'Connected to ' . $this->config['app_name'],
            'server_time' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function activateLicense(): void
    {
        $product = $this->authenticate();
        $this->throttle('activate'); // license-code guessing stops at 10 misses/10min/IP
        $body = $this->body();

        $productId = (string) ($body['product_id'] ?? '');
        $licenseCode = trim((string) ($body['license_code'] ?? ''));
        $clientName = trim((string) ($body['client_name'] ?? ''));

        if ($productId !== $product['product_id']) {
            $this->json(['status' => false, 'message' => 'Product ID mismatch.'], 400);
        }
        if ($licenseCode === '' || $clientName === '') {
            $this->json(['status' => false, 'message' => 'Missing license code or client name.'], 400);
        }

        $license = $this->findLicense($product['id'], $licenseCode);
        $domain = $this->clientDomain();
        $ip = $this->clientIp();

        if (! $license) {
            $this->log('activate', null, false, 'unknown license code', $domain, $ip);
            $this->json(['status' => false, 'message' => 'Invalid license code.'], 200);
        }

        $validity = $this->assertUsable($license);
        if ($validity !== null) {
            $this->log('activate', $license['id'], false, $validity['msg'], $domain, $ip);
            $this->json(['status' => false, 'status_code' => $validity['code'], 'message' => $validity['msg']], 200);
        }

        // Idempotent: re-activating the same domain refreshes and re-issues a cert.
        $existing = $this->findActivation($license['id'], $domain);

        if ($existing && ($existing['status'] ?? 'enabled') !== 'enabled') {
            $this->log('activate', $license['id'], false, 'activation disabled', $domain, $ip);
            $this->json([
                'status' => false,
                'status_code' => 'ACTIVATION_DISABLED',
                'message' => 'This activation has been disabled. Contact Ez IT Solutions.',
            ], 200);
        }

        if (! $existing) {
            $count = $this->activationCount($license['id']);
            if ($count >= (int) $license['max_activations']) {
                $this->log('activate', $license['id'], false, 'max activations reached', $domain, $ip);
                $this->json([
                    'status' => false,
                    'status_code' => 'ACTIVATED_MAXIMUM_ALLOWED_PRODUCT_INSTANCES',
                    'message' => sprintf(
                        'This license is already activated on %d domain(s), the maximum allowed. Deactivate it on another site or raise the limit in the admin panel.',
                        $count
                    ),
                ], 200);
            }

            $this->db->prepare(
                'INSERT INTO activations (license_id, domain, site_url, ip) VALUES (?, ?, ?, ?)'
            )->execute([$license['id'], $domain, $this->siteUrl(), $ip]);
        } else {
            $this->db->prepare(
                'UPDATE activations SET last_check_at = CURRENT_TIMESTAMP, ip = ?, site_url = ? WHERE id = ?'
            )->execute([$ip, $this->siteUrl(), $existing['id']]);
        }

        $cert = Certificate::issue(
            $this->config['server_secret'],
            $product['api_key'],
            $product['product_id'],
            $license['code'],
            $clientName,
            $domain,
            $ip,
            $license['expires_at'],
            ['features' => $this->features($license), 'recovery_digest' => $this->recoveryDigest($product)]
        );

        $this->log('activate', $license['id'], true, $existing ? 're-activated' : 'activated', $domain, $ip);

        $this->json([
            'status' => true,
            'message' => 'License activated successfully.',
            'lic_response' => $cert,
        ]);
    }

    /**
     * Under-construction gate login. The WP plugin posts product_id +
     * license_code + username + password; we verify against license_users
     * and return a signed access token the plugin checks locally with the
     * product API key (no server round-trip per page view).
     *
     * Token format: <64-char HMAC-SHA256 hex>.<base64url(JSON)> keyed with
     * "{$productApiKey}|access".
     */
    private function accessLogin(): void
    {
        $product = $this->authenticate();
        $body = $this->body();

        $productId = (string) ($body['product_id'] ?? '');
        $licenseCode = trim((string) ($body['license_code'] ?? ''));
        $username = strtolower(trim((string) ($body['username'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($productId !== $product['product_id']) {
            $this->json(['status' => false, 'message' => 'Product ID mismatch.'], 400);
        }

        $this->throttle('access_login', 8); // gate-user password guessing

        $license = $this->findLicense($product['id'], $licenseCode);
        $domain = $this->clientDomain();
        $ip = $this->clientIp();

        if (! $license || $license['status'] !== 'active') {
            $this->log('access_login', $license['id'] ?? null, false, 'invalid license', $domain, $ip);
            $this->json(['status' => false, 'message' => 'Invalid license.'], 200);
        }

        $stmt = $this->db->prepare('SELECT * FROM license_users WHERE license_id = ? AND username = ?');
        $stmt->execute([$license['id'], $username]);
        $user = $stmt->fetch();

        if (! $user || ! password_verify($password, $user['password_hash'])) {
            $this->log('access_login', $license['id'], false, 'bad credentials: ' . $username, $domain, $ip);
            $this->json(['status' => false, 'message' => 'Invalid username or password.'], 200);
        }

        $payload = [
            'sub' => $user['username'],
            'name' => $user['name'],
            'license_id' => $license['id'],
            'domain' => $domain,
            'exp' => time() + 12 * 3600,
        ];
        $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $payloadB64, $product['api_key'] . '|access');

        $this->log('access_login', $license['id'], true, $user['username'], $domain, $ip);

        $this->json(['status' => true, 'message' => 'Access granted.', 'token' => $sig . '.' . $payloadB64]);
    }

    private function verifyLicense(): void
    {
        $product = $this->authenticate();
        $body = $this->body();
        $domain = $this->clientDomain();

        $payload = $this->parseLicenseFile($product, (string) ($body['license_file'] ?? ''));

        $digest = $this->recoveryDigest($product); // ships on EVERY response, success or denial

        if ($payload === null || ($payload['product_id'] ?? '') !== $product['product_id']) {
            $this->log('verify', null, false, 'invalid certificate', $domain);
            $this->json(['status' => false, 'recovery_digest' => $digest, 'message' => 'Invalid license certificate.'], 200);
        }

        $license = $this->findLicense($product['id'], $payload['license_code']);

        if (! $license) {
            $this->log('verify', null, false, 'license deleted', $domain);
            $this->json(['status' => false, 'recovery_digest' => $digest, 'message' => 'License not found.'], 200);
        }

        $reason = $this->assertUsable($license);
        if ($reason !== null) {
            $this->log('verify', $license['id'], false, $reason['msg'], $domain);
            $this->json(['status' => false, 'status_code' => $reason['code'], 'recovery_digest' => $digest, 'message' => $reason['msg']], 200);
        }

        $activation = $this->findActivation($license['id'], $domain);
        if (! $activation || ($payload['domain'] ?? '') !== $domain) {
            $this->log('verify', $license['id'], false, 'domain not activated: ' . $domain, $domain);
            $this->json(['status' => false, 'status_code' => 'DOMAIN_NOT_ACTIVATED', 'recovery_digest' => $digest, 'message' => 'License is not activated for this domain.'], 200);
        }

        if (($activation['status'] ?? 'enabled') !== 'enabled') {
            $this->log('verify', $license['id'], false, 'activation disabled', $domain);
            $this->json([
                'status' => false,
                'status_code' => 'ACTIVATION_DISABLED',
                'recovery_digest' => $digest,
                'message' => 'This activation has been disabled. Contact Ez IT Solutions.',
            ], 200);
        }

        $this->db->prepare('UPDATE activations SET last_check_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$activation['id']]);

        $this->log('verify', $license['id'], true, null, $domain);

        $this->json([
            'status' => true,
            'message' => 'License verified.',
            'licensed_to' => $license['client_name'],
            'activated_at' => $activation['activated_at'],
            'expires_at' => $license['expires_at'],
            'recovery_digest' => $digest,
            'config_version' => $this->configVersion(),
        ]);
    }

    private function deactivateLicense(): void
    {
        $product = $this->authenticate();
        $body = $this->body();

        // Mode A — {license_file}: site self-deactivation.
        if (! empty($body['license_file'])) {
            $payload = $this->parseLicenseFile($product, (string) $body['license_file']);

            if ($payload === null) {
                $this->log('deactivate', null, false, 'invalid certificate');
                $this->json(['status' => false, 'message' => 'Invalid license certificate.'], 200);
            }

            $license = $this->findLicense($product['id'], $payload['license_code']);
            if (! $license) {
                $this->json(['status' => false, 'message' => 'License not found.'], 200);
            }

            $this->db->prepare('DELETE FROM activations WHERE license_id = ? AND domain = ?')
                ->execute([$license['id'], $payload['domain']]);

            $this->log('deactivate', $license['id'], true, 'self-deactivated', $payload['domain']);

            $this->json(['status' => true, 'message' => 'License deactivated.']);
        }

        // Mode B — {license_code, client_name}: admin/CLI revoke of a specific domain.
        $licenseCode = trim((string) ($body['license_code'] ?? ''));
        $license = $licenseCode !== '' ? $this->findLicense($product['id'], $licenseCode) : null;

        if (! $license) {
            $this->json(['status' => false, 'message' => 'License not found.'], 200);
        }

        // Revoke every activation (the CMS uses this to free the seat, then re-activates here).
        // Push first — after the delete the site_url join is gone.
        LicensePush::license($this->db, (int) $license['id'], 'license revoked');
        $this->db->prepare('DELETE FROM activations WHERE license_id = ?')->execute([$license['id']]);

        $this->log('deactivate', $license['id'], true, 'revoked all activations', $this->clientDomain());

        $this->json(['status' => true, 'message' => 'License revoked.']);
    }

    private function checkUpdate(): void
    {
        $product = $this->authenticate();
        $body = $this->body();
        $current = (string) ($body['current_version'] ?? '0.0.0');

        // Every check declares LB-URL — keep a per-product site registry
        // so LicensePush reaches installs that never activate a license
        // (free catalog items, self products like EzIT Core). Best-effort:
        // a registry hiccup must never break update checks.
        if ($site = $this->siteUrl()) {
            try {
                $q = $this->db->prepare('SELECT id FROM product_sites WHERE product_id = ? AND site_url = ?');
                $q->execute([(int) $product['id'], $site]);
                if ($sid = $q->fetchColumn()) {
                    $this->db->prepare('UPDATE product_sites SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?')
                        ->execute([$sid]);
                } else {
                    $this->db->prepare('INSERT INTO product_sites (product_id, site_url) VALUES (?, ?)')
                        ->execute([(int) $product['id'], $site]);
                }
            } catch (\PDOException) { /* registry is best-effort */ }
        }

        // disabled = withdrawn from circulation — the site never hears
        // about it; the next-enabled release is the effective "latest".
        $stmt = $this->db->prepare(
            'SELECT * FROM updates WHERE product_id = ? AND disabled = 0 ORDER BY release_date DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$product['id']]);
        $update = $stmt->fetch();

        if (! $update || version_compare($update['version'], $current, '<=')) {
            $this->json(['status' => true, 'message' => 'No update available.', 'config_version' => $this->configVersion()]);
        }

        $this->log('update_check', null, true, $current . ' → ' . $update['version'], $this->clientDomain());

        $this->json([
            'status' => true,
            'update_id' => $update['update_id'],
            'version' => $update['version'],
            'release_date' => substr($update['release_date'], 0, 10),
            'summary' => $update['summary'],
            'changelog' => $update['changelog'],
            'has_sql' => (bool) $update['has_sql'],
            // Forced, past its scheduled time (NULL = immediately), and
            // this site is inside the target list when one is set — a
            // targeted rollout reports forced only to named domains.
            'forced' => ! empty($update['forced'])
                && self::forceAppliesTo($update['force_targets'] ?? null, $this->clientDomain())
                && ((string) ($update['force_at'] ?? '') === ''
                    || strtotime((string) $update['force_at']) <= time()),
            'config_version' => $this->configVersion(),
        ]);
    }

    /**
     * Release history for the authenticated product — powers the
     * client site's "pick a version" installer (downgrades included).
     * Any listed update_id is downloadable via the existing
     * download_update/main/{id} route under the same auth rules.
     */
    private function versions(): void
    {
        $product = $this->authenticate();

        $stmt = $this->db->prepare(
            'SELECT update_id, version, release_date, summary, forced
             FROM updates WHERE product_id = ? AND disabled = 0
             ORDER BY release_date DESC, id DESC LIMIT 50'
        );
        $stmt->execute([(int) $product['id']]);

        $this->json([
            'status' => true,
            'versions' => array_map(static fn (array $r) => [
                'update_id'    => $r['update_id'],
                'version'      => $r['version'],
                'release_date' => substr((string) $r['release_date'], 0, 10),
                'summary'      => $r['summary'],
                'forced'       => ! empty($r['forced']),
            ], $stmt->fetchAll()),
        ]);
    }

    /**
     * Bootstrap configuration for EzIT Core. Returns the latest
     * published server config (API base, endpoint map, services,
     * notices) merged with the requesting product's details.
     *
     * Sites cache the doc and compare `config_version` — when it
     * changes they re-read it (and it also rides along on every
     * check_update response as a staleness hint).
     */
    private function getConfig(): void
    {
        $product = $this->authenticate();
        $cfg = ServerConfig::current($this->db);

        if ($cfg === null) {
            // Should never happen (migrate seeds v1) — degrade gracefully.
            $cfg = ['version' => 0, 'payload' => ServerConfig::defaultDoc(), 'published_at' => null];
        }

        $this->log('config_fetch', null, true, 'served v' . $cfg['version'] . ' for ' . $product['product_id']);

        $payload = $cfg['payload'];
        $payload['product'] = [
            'product_id' => $product['product_id'],
            'name' => $product['name'],
            'latest_version' => $product['latest_version'],
        ];

        $this->json([
            'status' => true,
            'config_version' => $cfg['version'],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'config' => $payload,
        ]);
    }

    private function getUpdateSize(string $updateId): void
    {
        $product = $this->authenticate();
        $update = $this->findUpdate($product['id'], $updateId);

        $size = $update && $update['file_path'] && is_file($update['file_path'])
            ? filesize($update['file_path'])
            : (int) ($update['file_size'] ?? 0);

        header('Content-Length: ' . max(1, (int) $size));
        http_response_code(200);
    }

    private function downloadUpdate(string $updateId): void
    {
        $product = $this->authenticate();
        $body = $this->body();

        // Require a valid license cert on this domain (client expects 401 otherwise).
        $payload = $this->parseLicenseFile($product, (string) ($body['license_file'] ?? ''));
        $license = $payload ? $this->findLicense($product['id'], $payload['license_code']) : null;

        if (! $license || $this->assertUsable($license) !== null
            || ! $this->findActivation($license['id'], $this->clientDomain())) {
            // Marketplace installs: a product published as is_free can be
            // fetched with just its api_key (authenticate() already proved
            // possession) plus a declared LB-URL — the site licenses itself
            // normally only after install.
            $free = $this->db->prepare(
                'SELECT 1 FROM catalog_items WHERE product_id = ? AND published = 1 AND is_free = 1'
            );
            $free->execute([$product['id']]);
            if (! $free->fetchColumn() || ! $this->siteUrl()) {
                $this->json(['status' => false, 'message' => 'Active license required.'], 401);
            }
            $license = null;
        }

        $update = $this->findUpdate($product['id'], $updateId);
        if (! $update || ! $update['file_path'] || ! is_file($update['file_path'])) {
            $this->json(['status' => false, 'message' => 'Update package not found.'], 404);
        }

        $this->log('download', $license ? (int) $license['id'] : null, true,
            'update ' . $update['version'] . ($license ? '' : ' (free catalog install)'), $this->clientDomain());

        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($update['file_path']));
        header('Content-Disposition: attachment; filename="update_' . $update['version'] . '.zip"');
        readfile($update['file_path']);
        exit;
    }

    /* ── marketplace catalog — no api_key required; soft-gated on LB-URL ── */

    private function catalogList(): void
    {
        if (! $this->siteUrl()) {
            $this->json(['status' => false, 'message' => 'Missing LB-URL header.'], 400);
        }
        $domain = $this->clientDomain();
        $this->log('catalog', null, true, 'catalog list', $domain);

        // Which products does the calling site already have activated?
        $stmt = $this->db->prepare(
            'SELECT DISTINCT l.product_id
             FROM activations a JOIN licenses l ON l.id = a.license_id
             WHERE a.domain = ?'
        );
        $stmt->execute([$domain]);
        $installed = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        $held = $this->siteLicenseCodes($domain);

        $rows = $this->db->query(
            'SELECT c.slug, c.type, c.category, c.excerpt, c.thumb, c.price, c.is_free, c.license_scope,
                    p.id AS product_ref, p.product_id, p.name, p.latest_version AS version
             FROM catalog_items c JOIN products p ON p.id = c.product_id
             WHERE c.published = 1 AND p.status = \'enabled\'
             ORDER BY c.category, p.name'
        )->fetchAll();

        // Scoped listings appear only for sites activated under a listed code.
        $rows = array_values(array_filter($rows, fn (array $r) =>
            $this->catalogVisible($r['license_scope'] ?? null, $held)));

        $this->json([
            'status' => true,
            'items'  => array_map(fn (array $r) => [
                'slug'       => $r['slug'],
                'name'       => $r['name'],
                'product_id' => $r['product_id'],
                'type'       => $r['type'],
                'category'   => $r['category'],
                'excerpt'    => $r['excerpt'],
                'thumb'      => $r['thumb'],
                'version'    => $r['version'],
                'price'      => $r['price'] !== null ? (float) $r['price'] : null,
                'free'       => (bool) $r['is_free'],
                'installed'  => isset($installed[(int) $r['product_ref']]),
            ], $rows),
        ]);
    }

    private function catalogItem(string $slug): void
    {
        if (! $this->siteUrl()) {
            $this->json(['status' => false, 'message' => 'Missing LB-URL header.'], 400);
        }
        $stmt = $this->db->prepare(
            'SELECT c.*, p.product_id, p.name, p.latest_version AS version, p.api_key
             FROM catalog_items c JOIN products p ON p.id = c.product_id
             WHERE c.slug = ? AND c.published = 1 AND p.status = \'enabled\''
        );
        $stmt->execute([$slug]);
        $r = $stmt->fetch();
        if (! $r || ! $this->catalogVisible($r['license_scope'] ?? null,
                $this->siteLicenseCodes($this->clientDomain()))) {
            // 404, not 403 — scoped items shouldn't leak their existence.
            $this->json(['status' => false, 'message' => 'Catalog item not found.'], 404);
        }
        $this->log('catalog', null, true, 'catalog detail: ' . $slug, $this->clientDomain());

        $item = [
            'slug'        => $r['slug'],
            'name'        => $r['name'],
            'product_id'  => $r['product_id'],
            'type'        => $r['type'],
            'category'    => $r['category'],
            'excerpt'     => $r['excerpt'],
            'body'        => $r['body'],
            'thumb'       => $r['thumb'],
            'screenshots' => json_decode((string) ($r['screenshots'] ?? ''), true) ?: [],
            'version'     => $r['version'],
            'price'       => $r['price'] !== null ? (float) $r['price'] : null,
            'free'        => (bool) $r['is_free'],
        ];
        // Installable without a purchase → hand over the api_key the site's
        // core.json needs to activate it. Paid items never expose it here.
        if ((int) $r['is_free'] === 1) {
            $item['api_key'] = $r['api_key'];
        }
        $this->json(['status' => true, 'item' => $item]);
    }

    /* ──

    /* ─────────────────────────── helpers ─────────────────────────── */

    /** Validates the LB-API-KEY header → returns product row or exits. */
    private function authenticate(): array
    {
        $apiKey = $_SERVER['HTTP_LB_API_KEY'] ?? '';

        $stmt = $this->db->prepare('SELECT * FROM products WHERE api_key = ?');
        $stmt->execute([$apiKey]);
        $product = $stmt->fetch();

        if (! $product) {
            $this->json(['status' => false, 'status_code' => 'INVALID_API_KEY', 'message' => 'Invalid API key.'], 200);
        }

        if (($product['status'] ?? 'enabled') !== 'enabled') {
            $this->json([
                'status' => false,
                'status_code' => 'PRODUCT_DISABLED',
                'message' => 'This product is no longer available. Contact Ez IT Solutions.',
            ], 200);
        }

        return $product;
    }

    private function parseLicenseFile(array $product, string $file): ?array
    {
        if ($file === '') {
            return null;
        }

        return Certificate::parse($this->config['server_secret'], $product['api_key'], $file);
    }

    /** licenses.features is a JSON array column; certs always get a list. */
    private function features(array $license): array
    {
        $f = json_decode((string) ($license['features'] ?? ''), true);
        return is_array($f) ? array_values($f) : [];
    }

    private function findLicense(int $productId, string $code): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*, p.product_id AS product_ref, p.api_key AS product_api_key
             FROM licenses l JOIN products p ON p.id = l.product_id
             WHERE l.product_id = ? AND l.code = ?'
        );
        $stmt->execute([$productId, $code]);

        return $stmt->fetch() ?: null;
    }

    private function findActivation(int $licenseId, string $domain): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM activations WHERE license_id = ? AND domain = ?');
        $stmt->execute([$licenseId, $domain]);

        return $stmt->fetch() ?: null;
    }

    private function activationCount(int $licenseId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM activations WHERE license_id = ?');
        $stmt->execute([$licenseId]);

        return (int) $stmt->fetchColumn();
    }

    /** Returns ['code','msg'] if the license is not usable, else null. */
    private function assertUsable(array $license): ?array
    {
        if ($license['status'] === 'suspended') {
            return ['code' => 'LICENSE_SUSPENDED', 'msg' => 'This license has been suspended. Contact Ez IT Solutions.'];
        }
        if ($license['status'] !== 'active') {
            return ['code' => 'LICENSE_INACTIVE', 'msg' => 'This license is not active.'];
        }
        if ($license['expires_at'] !== null && strtotime($license['expires_at']) < time()) {
            return ['code' => 'LICENSE_EXPIRED', 'msg' => 'This license has expired.'];
        }

        return null;
    }

    /** License codes the domain holds via an enabled activation + active license. */
    private function siteLicenseCodes(string $domain): array
    {
        $stmt = $this->db->prepare(
            "SELECT l.code FROM activations a JOIN licenses l ON l.id = a.license_id
             WHERE a.domain = ? AND a.status = 'enabled' AND l.status = 'active'"
        );
        $stmt->execute([$domain]);
        return array_fill_keys(array_map('strtoupper', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** NULL/empty scope = public; otherwise the site must hold a listed code. */
    private function catalogVisible(?string $scopeJson, array $held): bool
    {
        $scope = json_decode((string) $scopeJson, true);
        if (! is_array($scope) || ! $scope) {
            return true;
        }
        return (bool) array_intersect(array_map('strtoupper', $scope), array_keys($held));
    }

    /**
     * Is a forced rollout aimed at this site? NULL/empty targets = all
     * sites; a non-empty JSON array names the domains it applies to.
     * Malformed data fails OPEN (all sites) rather than silently
     * un-forcing a release the admin already armed.
     */
    private static function forceAppliesTo(?string $targets, string $domain): bool
    {
        if ($targets === null || trim($targets) === '') {
            return true;
        }
        $list = json_decode($targets, true);
        return ! is_array($list) || $list === []
            || in_array($domain, array_map('strval', $list), true);
    }

    private function findUpdate(int $productId, string $updateId): ?array
    {
        // disabled releases can't be fetched even by update_id — the
        // row stays for records, but nothing may install it again.
        $stmt = $this->db->prepare('SELECT * FROM updates WHERE product_id = ? AND update_id = ? AND disabled = 0');
        $stmt->execute([$productId, $updateId]);

        return $stmt->fetch() ?: null;
    }

    private function body(): array
    {
        static $body = null;
        if ($body === null) {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw ?: '', true);
            if (! is_array($body)) {
                $body = $_POST ?: [];
            }
        }

        return $body;
    }

    private function siteUrl(): ?string
    {
        $url = $_SERVER['HTTP_LB_URL'] ?? null;

        return $url ? rtrim($url, '/') : null;
    }

    private function clientDomain(): string
    {
        return Database::normalizeDomain($this->siteUrl() ?: ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /**
     * REMOTE_ADDR first — it's the TCP peer and can't be spoofed, so the
     * ip column in activity_logs stays trustworthy enough to throttle on.
     * LB-IP (the site's SERVER_ADDR) is kept only as a fallback.
     */
    private function clientIp(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_LB_IP'] ?? null;
    }

    /**
     * Cheap per-IP rate limit backed by activity_logs. Counts FAILED
     * rows for this action from the caller's real IP inside the window
     * and 429s when the cap is hit — throttled attempts also log as
     * failures, so sustained abuse keeps the window alive.
     */
    private function throttle(string $action, int $maxFails = 10, int $windowMin = 10): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM activity_logs
             WHERE action = ? AND success = 0 AND ip = ?
               AND created_at >= datetime('now', ?)"
        );
        $stmt->execute([$action, $this->clientIp() ?? '', "-{$windowMin} minutes"]);

        if ((int) $stmt->fetchColumn() >= $maxFails) {
            $this->log($action, null, false, 'rate limited');
            $this->json(['status' => false, 'message' => 'Too many failed attempts. Try again later.'], 429);
        }
    }

    private function configVersion(): int
    {
        return ServerConfig::current($this->db)['version'] ?? 0;
    }

    /**
     * Offline emergency-override proof shipped to sites: HMAC digest of
     * the product's recovery code keyed with the product api_key. The raw
     * code never leaves the server; a site validates an entered code by
     * recomputing this digest (works with the server completely offline).
     */
    private function recoveryDigest(array $product): ?string
    {
        // Stored digest (hashed-storage model) wins; fall back to the
        // legacy plaintext column for rows that haven't migrated yet.
        $digest = (string) ($product['recovery_digest'] ?? '');
        if ($digest !== '') {
            return $digest;
        }
        $code = (string) ($product['recovery_code'] ?? '');

        return $code === '' ? null : Database::recoveryDigestFor($code, $product['api_key']);
    }

    /**
     * Seconds until expected restoration — plain integer for the
     * Retry-After header. Clients clamp to 5 min – 1 h on their side;
     * we just floor it at 30 s so a config typo can't spam-request.
     */
    private function maintenanceRetryAfter(): int
    {
        return max(30, (int) ($this->config['maintenance_retry_after'] ?? 300));
    }

    /**
     * Planned-outage switch: set 'maintenance' => true in config.php, or
     * drop a flag file at data/.maintenance (toggleable from the dashboard
     * via PUT /admin-api/maintenance). File-flag survives DB outages.
     */
    private function maintenanceMode(): bool
    {
        return ! empty($this->config['maintenance'])
            || is_file(dirname(__DIR__) . '/data/.maintenance');
    }

    private function log(string $action, ?int $licenseId, bool $success, ?string $detail = null, ?string $domain = null, ?string $ip = null): void
    {
        Database::log($this->db, $action, $licenseId, $domain ?? $this->clientDomain(), $ip ?? $this->clientIp(), $detail, $success);
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $body = json_encode($data);
        header('Content-Length: ' . strlen($body));
        echo $body;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ignore_user_abort(true);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            flush();
        }
        LicensePush::flush($this->db, (int) ($this->config['push_max_sites'] ?? 0) ?: null);
        exit;
    }
}
