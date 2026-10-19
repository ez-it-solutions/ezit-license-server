<?php

declare(strict_types=1);

namespace EzIT\LicenseServer;

use PDO;
use PDOException;

/**
 * Session-authenticated JSON API backing the admin dashboard SPA.
 * Mounted under /admin-api/*.
 */
final class AdminApi
{
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function handle(string $method, string $path): void
    {
        $path = trim($path, '/');

        // Public admin endpoints
        if ($path === 'login' && $method === 'POST') {
            $this->login();
        }
        if ($path === 'session' && $method === 'GET') {
            $this->sessionInfo();
        }

        $this->requireAuth();
        if ($method !== 'GET') {
            $this->requireCsrf();
        }

        $segments = explode('/', $path);
        $resource = $segments[0] ?? '';
        $id = $segments[1] ?? null;
        $sub = $segments[2] ?? null;

        match (true) {
            $path === 'logout' && $method === 'POST' => $this->logout(),
            $path === 'stats' => $this->stats(),
            $resource === 'products' && $method === 'GET' && ! $id => $this->listProducts(),
            $resource === 'products' && $method === 'POST' && ! $id => $this->createProduct(),
            $resource === 'products' && $method === 'PUT' && $id => $this->updateProduct((int) $id),
            $resource === 'products' && $id && $sub === 'recovery-code' && $method === 'POST' => $this->regenerateRecoveryCode((int) $id),
            $resource === 'products' && $method === 'DELETE' && $id => $this->deleteProduct((int) $id),
            $resource === 'licenses' && $method === 'GET' && ! $id => $this->listLicenses(),
            $resource === 'licenses' && $method === 'POST' && ! $id => $this->createLicense(),
            $resource === 'licenses' && $method === 'PUT' && $id => $this->updateLicense((int) $id),
            $resource === 'licenses' && $method === 'DELETE' && $id => $this->deleteLicense((int) $id),
            $resource === 'licenses' && $id && $sub === 'activations' && $method === 'GET' => $this->licenseActivations((int) $id),
            $resource === 'licenses' && $id && $sub === 'users' && $method === 'GET' => $this->licenseUsers((int) $id),
            $resource === 'licenses' && $id && $sub === 'users' && $method === 'POST' => $this->createLicenseUser((int) $id),
            $resource === 'licenses' && $id && $sub === 'users' && $method === 'DELETE' && isset($segments[3]) => $this->deleteLicenseUser((int) $id, (int) $segments[3]),
            $path === 'package-inspect' && $method === 'POST' => $this->inspectPackage(),
            $resource === 'updates' && $method === 'GET' && ! $id => $this->listUpdates(),
            $resource === 'updates' && $method === 'POST' && ! $id => $this->createUpdate(),
            $resource === 'updates' && $method === 'DELETE' && $id => $this->deleteUpdate((int) $id),
            $path === 'catalog-upload' && $method === 'POST' => $this->catalogUpload(),
            $resource === 'catalog' && $method === 'GET' => $this->listCatalog(),
            $resource === 'catalog' && $method === 'POST' && ! $id => $this->createCatalogItem(),
            $resource === 'catalog' && $method === 'PUT' && $id => $this->updateCatalogItem((int) $id),
            $resource === 'catalog' && $method === 'DELETE' && $id => $this->deleteCatalogItem((int) $id),
            $resource === 'activations' && $method === 'GET' && ! $id => $this->listActivations(),
            $resource === 'activations' && $method === 'PUT' && $id => $this->updateActivation((int) $id),
            $resource === 'activations' && $method === 'DELETE' && $id => $this->deleteActivation((int) $id),
            $resource === 'logs' && $method === 'GET' => $this->listLogs(),
            $path === 'logs/purge' && $method === 'POST' => $this->purgeLogs(),
            $resource === 'log-rules' && $method === 'GET' => $this->listLogRules(),
            $resource === 'log-rules' && $method === 'POST' => $this->createLogRule(),
            $resource === 'log-rules' && $method === 'PUT' && $id => $this->updateLogRule((int) $id),
            $resource === 'log-rules' && $method === 'DELETE' && $id => $this->deleteLogRule((int) $id),
            $resource === 'config' && $method === 'GET' && $id => $this->getConfigVersion((int) $id),
            $resource === 'config' && $method === 'GET' => $this->getConfig(),
            $resource === 'config' && $method === 'POST' => $this->publishConfig(),
            $resource === 'account' && $method === 'PUT' => $this->updateAccount(),
            $path === 'site-users' && $method === 'GET' => $this->siteUsers(),
            $path === 'site-login-capable' && $method === 'GET' => $this->siteLoginCapable(),
            $path === 'site-login' && $method === 'POST' => $this->siteLogin(),
            $resource === 'site-logins' && $method === 'GET' && ! $id => $this->listSiteLogins(),
            $resource === 'site-logins' && $method === 'POST' && ! $id => $this->createSiteLogin(),
            $resource === 'site-logins' && $id && $sub === 'reveal' && $method === 'POST' => $this->revealSiteLogin((int) $id),
            $resource === 'site-logins' && $method === 'DELETE' && $id => $this->deleteSiteLogin((int) $id),
            $path === 'maintenance' && $method === 'GET' => $this->getMaintenance(),
            $path === 'maintenance' && $method === 'PUT' => $this->setMaintenance(),
            $path === 'settings' && $method === 'GET' => $this->getSettings(),
            $path === 'settings' && $method === 'PUT' => $this->updateSettings(),
            default => $this->json(['error' => 'Not found'], 404),
        };
    }

    /* ─────────────────────────── auth ─────────────────────────── */

    private function login(): void
    {
        // Brute-force guard — 8 failed logins per IP per 10 minutes.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM activity_logs
             WHERE action = 'admin_login_fail' AND success = 0 AND ip = ?
               AND created_at >= datetime('now', '-10 minutes')"
        );
        $stmt->execute([$ip]);
        if ((int) $stmt->fetchColumn() >= 8) {
            $this->json(['error' => 'Too many failed attempts. Try again later.'], 429);
        }

        $body = $this->body();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $stmt = $this->db->prepare('SELECT * FROM admins WHERE email = ?');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (! $admin || ! password_verify($password, $admin['password_hash'])) {
            Database::log($this->db, 'admin_login_fail', null, null, $_SERVER['REMOTE_ADDR'] ?? null, $email);
            $this->json(['error' => 'Invalid credentials.'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['admin_id']   = $admin['id'];
        $_SESSION['admin_role'] = $admin['role'] ?? 'super_admin';
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        Database::log($this->db, 'admin_login', null, null, $_SERVER['REMOTE_ADDR'] ?? null, $email, true);

        $this->json([
            'admin' => ['id' => $admin['id'], 'name' => $admin['name'], 'email' => $admin['email'], 'role' => $admin['role'] ?? 'super_admin'],
            'csrf' => $_SESSION['csrf'],
        ]);
    }

    private function sessionInfo(): void
    {
        if (empty($_SESSION['admin_id'])) {
            $this->json(['authenticated' => false]);
        }

        $stmt = $this->db->prepare('SELECT id, name, email, role FROM admins WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();
        $_SESSION['admin_role'] = $admin['role'] ?? 'super_admin'; // keep session fresh if the column changes later

        $this->json([
            'authenticated' => true,
            'admin' => $admin,
            'csrf' => $_SESSION['csrf'] ?? ($_SESSION['csrf'] = bin2hex(random_bytes(32))),
        ]);
    }

    private function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        $this->json(['ok' => true]);
    }

    private function requireAuth(): void
    {
        if (empty($_SESSION['admin_id'])) {
            $this->json(['error' => 'Unauthenticated'], 401);
        }
    }

    /**
     * Role → capabilities map. 'super_admin' implicitly has every cap;
     * future roles (staff, support…) grant named caps here — endpoint
     * handlers call requireCap() so adding a role is a one-line change.
     */
    private const ROLE_CAPS = [
        // 'staff' => ['site_login_support'], // e.g. support-only logins
    ];

    private function adminRole(): string
    {
        return (string) ($_SESSION['admin_role'] ?? 'super_admin');
    }

    private function can(string $cap): bool
    {
        $role = $this->adminRole();
        if ($role === 'super_admin') {
            return true;
        }

        return in_array($cap, self::ROLE_CAPS[$role] ?? [], true);
    }

    private function requireCap(string $cap): void
    {
        if (! $this->can($cap)) {
            $this->json(['error' => 'Your role does not allow this action.'], 403);
        }
    }

    private function requireCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (! hash_equals($_SESSION['csrf'] ?? '', $token)) {
            $this->json(['error' => 'CSRF token mismatch'], 419);
        }
    }

    /* ─────────────────────────── stats ─────────────────────────── */

    private function stats(): void
    {
        $this->json([
            'products' => (int) $this->db->query('SELECT COUNT(*) FROM products')->fetchColumn(),
            'licenses' => (int) $this->db->query('SELECT COUNT(*) FROM licenses')->fetchColumn(),
            'active_licenses' => (int) $this->db->query("SELECT COUNT(*) FROM licenses WHERE status = 'active'")->fetchColumn(),
            'activations' => (int) $this->db->query('SELECT COUNT(*) FROM activations')->fetchColumn(),
            'checks_24h' => (int) $this->db->query("SELECT COUNT(*) FROM activity_logs WHERE created_at >= datetime('now', '-1 day')")->fetchColumn(),
            'recent_activity' => $this->db->query(
                'SELECT action, domain, ip, detail, success, created_at FROM activity_logs ORDER BY id DESC LIMIT 8'
            )->fetchAll(),
        ]);
    }

    /* ─────────────────────────── products ─────────────────────────── */

    private function listProducts(): void
    {
        $rows = $this->db->query(
            'SELECT p.*, (SELECT COUNT(*) FROM licenses l WHERE l.product_id = p.id) AS license_count
             FROM products p ORDER BY p.name'
        )->fetchAll();

        // Recovery codes are stored hashed — never ship them back out.
        // The SPA only needs to know one exists.
        foreach ($rows as &$row) {
            $row['has_recovery_code'] = ! empty($row['recovery_code_hash']) || ! empty($row['recovery_code']);
            unset($row['recovery_code'], $row['recovery_code_hash'], $row['recovery_digest']);
        }
        unset($row);

        $this->json(['data' => $rows]);
    }

    private function createProduct(): void
    {
        $b = $this->body();
        $productId = strtoupper(trim((string) ($b['product_id'] ?? '')));
        $name = trim((string) ($b['name'] ?? ''));

        if ($name === '') {
            $this->json(['error' => 'Product name is required.'], 422);
        }
        if ($productId === '') {
            // Blank → mint the LicenseBox-style ID ourselves (5F061D22-style).
            do {
                $productId = strtoupper(bin2hex(random_bytes(4)));
            } while ($this->productIdTaken($productId));
        }
        if ($this->productIdTaken($productId)) {
            $this->json(['error' => 'Product ID "' . $productId . '" is already in use.'], 409);
        }

        $apiKey = bin2hex(random_bytes(10)); // 20-char key, same style as Botble's
        $recoveryCode = Database::generateRecoveryCode();
        $this->db->prepare(
            'INSERT INTO products (product_id, name, api_key, latest_version, recovery_code_hash, recovery_digest) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $productId,
            $name,
            $apiKey,
            trim((string) ($b['latest_version'] ?? '1.0.0')),
            Database::recoveryCodeHash($recoveryCode),
            Database::recoveryDigestFor($recoveryCode, $apiKey),
        ]);

        // The raw code is returned exactly once — store only its hashes.
        $this->json(['data' => [
            'id'            => $this->db->lastInsertId(),
            'product_id'    => $productId,
            'api_key'       => $apiKey,
            'recovery_code' => $recoveryCode,
        ]], 201);
    }

    private function productIdTaken(string $productId, int $exceptId = 0): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM products WHERE product_id = ? AND id != ?');
        $stmt->execute([$productId, $exceptId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function updateProduct(int $id): void
    {
        $b = $this->body();

        $productId = trim((string) ($b['product_id'] ?? ''));
        if ($productId === '') {
            $this->json(['error' => 'Product ID is required.'], 422);
        }
        if ($this->productIdTaken($productId, $id)) {
            $this->json(['error' => 'Product ID "' . $productId . '" is already in use by another product.'], 409);
        }

        // Manual key override or auto-regenerate — not both.
        $apiKey = null;
        if (! empty($b['api_key']) && empty($b['regenerate_key'])) {
            $apiKey = trim((string) $b['api_key']);
            if (! preg_match('/^[A-Za-z0-9]{8,64}$/', $apiKey)) {
                $this->json(['error' => 'API key must be 8-64 letters/digits.'], 422);
            }
            $dup = $this->db->prepare('SELECT COUNT(*) FROM products WHERE api_key = ? AND id != ?');
            $dup->execute([$apiKey, $id]);
            if ((int) $dup->fetchColumn() > 0) {
                $this->json(['error' => 'That API key is already used by another product.'], 409);
            }
        } elseif (! empty($b['regenerate_key'])) {
            $apiKey = bin2hex(random_bytes(10));
        }

        if ($apiKey !== null) {
            $this->db->prepare(
                'UPDATE products SET product_id = ?, name = ?, latest_version = ?, status = ?, api_key = ? WHERE id = ?'
            )->execute([
                $productId,
                trim((string) $b['name']),
                trim((string) $b['latest_version']),
                ($b['status'] ?? '') === 'disabled' ? 'disabled' : 'enabled',
                $apiKey,
                $id,
            ]);
        } else {
            $this->db->prepare(
                'UPDATE products SET product_id = ?, name = ?, latest_version = ?, status = ? WHERE id = ?'
            )->execute([
                $productId,
                trim((string) $b['name']),
                trim((string) $b['latest_version']),
                ($b['status'] ?? '') === 'disabled' ? 'disabled' : 'enabled',
                $id,
            ]);
        }

        // Rotating the api_key kills the old recovery digest (it's HMAC'd
        // with the key) — mint a fresh code keyed to the new secret and
        // hand it back once. Same contract as the regenerate endpoint.
        $recoveryCode = null;
        if ($apiKey !== null) {
            $recoveryCode = Database::generateRecoveryCode();
            $this->db->prepare(
                'UPDATE products SET recovery_code_hash = ?, recovery_digest = ?, recovery_code = NULL WHERE id = ?'
            )->execute([
                Database::recoveryCodeHash($recoveryCode),
                Database::recoveryDigestFor($recoveryCode, $apiKey),
                $id,
            ]);
        }

        Database::log($this->db, 'admin_product_update', null, null, null, 'product #' . $id, true);

        // Disabling a product invalidates every license under it — push
        // so sites reflect that now, not at next cron. (Skipped when the
        // key rotated: the site's stored key no longer authenticates.)
        if ($apiKey === null) {
            LicensePush::product($this->db, $id, 'product updated');
        }

        $this->json(['ok' => true, 'api_key' => $apiKey, 'recovery_code' => $recoveryCode]);
    }

    /**
     * POST /admin-api/products/{id}/recovery-code — issue a new offline
     * override code. The old one dies immediately (digest rotates); the
     * new code is returned once and stored only as hashes.
     */
    private function regenerateRecoveryCode(int $id): void
    {
        $stmt = $this->db->prepare('SELECT api_key FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $apiKey = (string) ($stmt->fetchColumn() ?: '');
        if ($apiKey === '') {
            $this->json(['error' => 'Product not found'], 404);
        }

        $code = Database::generateRecoveryCode();
        $this->db->prepare(
            'UPDATE products SET recovery_code_hash = ?, recovery_digest = ?, recovery_code = NULL WHERE id = ?'
        )->execute([
            Database::recoveryCodeHash($code),
            Database::recoveryDigestFor($code, $apiKey),
            $id,
        ]);

        Database::log($this->db, 'admin_recovery_code', null, null, null, 'product #' . $id, true);
        $this->json(['ok' => true, 'recovery_code' => $code]);
    }

    private function deleteProduct(int $id): void
    {
        $this->db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        $this->json(['ok' => true]);
    }

    /* ─────────────────────────── licenses ─────────────────────────── */

    private function listLicenses(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $sql = 'SELECT l.*, p.name AS product_name, p.product_id AS product_ref,
                       (SELECT COUNT(*) FROM activations a WHERE a.license_id = l.id) AS activation_count
                FROM licenses l JOIN products p ON p.id = l.product_id';
        $params = [];

        if ($q !== '') {
            $sql .= ' WHERE l.code LIKE ? OR l.client_name LIKE ? OR l.client_email LIKE ?';
            $params = ["%$q%", "%$q%", "%$q%"];
        }
        $sql .= ' ORDER BY l.id DESC LIMIT 500';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $this->json(['data' => $stmt->fetchAll()]);
    }

    private function createLicense(): void
    {
        $b = $this->body();

        $code = trim((string) ($b['code'] ?? '')) ?: self::generateCode();

        try {
            $this->db->prepare(
                'INSERT INTO licenses (product_id, code, client_name, client_email, status, max_activations, expires_at, notes, features)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (int) $b['product_id'],
                $code,
                trim((string) $b['client_name']),
                trim((string) ($b['client_email'] ?? '')) ?: null,
                in_array($b['status'] ?? '', ['active', 'suspended', 'expired'], true) ? $b['status'] : 'active',
                max(1, (int) ($b['max_activations'] ?? 1)),
                ($b['expires_at'] ?? null) ?: null,
                trim((string) ($b['notes'] ?? '')) ?: null,
                self::normalizeFeatures($b['features'] ?? null),
            ]);
        } catch (PDOException) {
            $this->json(['error' => 'License code "' . $code . '" is already in use.'], 409);
        }

        Database::log($this->db, 'admin_license_create', (int) $this->db->lastInsertId(), null, null, $code, true);

        $this->json(['data' => ['id' => $this->db->lastInsertId(), 'code' => $code]], 201);
    }

    private function updateLicense(int $id): void
    {
        $b = $this->body();
        $this->db->prepare(
            'UPDATE licenses SET client_name = ?, client_email = ?, status = ?, max_activations = ?, expires_at = ?, notes = ? WHERE id = ?'
        )->execute([
            trim((string) $b['client_name']),
            trim((string) ($b['client_email'] ?? '')) ?: null,
            in_array($b['status'] ?? '', ['active', 'suspended', 'expired'], true) ? $b['status'] : 'active',
            max(1, (int) ($b['max_activations'] ?? 1)),
            ($b['expires_at'] ?? null) ?: null,
            trim((string) ($b['notes'] ?? '')) ?: null,
            $id,
        ]);

        // Feature flags — only touched when explicitly sent, so the
        // current SPA (which doesn't send it) can't wipe them.
        if (array_key_exists('features', $b)) {
            $this->db->prepare('UPDATE licenses SET features = ? WHERE id = ?')
                ->execute([self::normalizeFeatures($b['features']), $id]);
        }

        LicensePush::license($this->db, $id, 'license updated');

        $this->json(['ok' => true]);
    }

    private function deleteLicense(int $id): void
    {
        LicensePush::license($this->db, $id, 'license deleted');
        $this->db->prepare('DELETE FROM licenses WHERE id = ?')->execute([$id]);
        $this->json(['ok' => true]);
    }

    private function licenseActivations(int $id): void
    {
        $stmt = $this->db->prepare('SELECT * FROM activations WHERE license_id = ? ORDER BY activated_at DESC');
        $stmt->execute([$id]);
        $this->json(['data' => $stmt->fetchAll()]);
    }

    /* ─────────────────────────── license users ─────────────────────────── */

    private function licenseUsers(int $id): void
    {
        $stmt = $this->db->prepare('SELECT id, username, name, created_at FROM license_users WHERE license_id = ? ORDER BY username');
        $stmt->execute([$id]);
        $this->json(['data' => $stmt->fetchAll()]);
    }

    private function createLicenseUser(int $id): void
    {
        $b = $this->body();
        $username = strtolower(trim((string) ($b['username'] ?? '')));
        $password = (string) ($b['password'] ?? '');
        $name = trim((string) ($b['name'] ?? ''));

        if (! preg_match('/^[a-z0-9._-]{3,80}$/', $username)) {
            $this->json(['error' => 'Username must be 3-80 chars: letters, numbers, dot, dash, underscore.'], 422);
        }
        if (strlen($password) < 8) {
            $this->json(['error' => 'Password must be at least 8 characters.'], 422);
        }

        try {
            $this->db->prepare('INSERT INTO license_users (license_id, username, password_hash, name) VALUES (?, ?, ?, ?)')
                ->execute([$id, $username, password_hash($password, PASSWORD_DEFAULT), $name ?: null]);
        } catch (PDOException) {
            $this->json(['error' => 'Username already exists for this license.'], 409);
        }

        Database::log($this->db, 'admin_user_create', $id, null, null, 'user ' . $username, true);

        $this->json(['data' => ['id' => $this->db->lastInsertId(), 'username' => $username]], 201);
    }

    private function deleteLicenseUser(int $licenseId, int $userId): void
    {
        $this->db->prepare('DELETE FROM license_users WHERE id = ? AND license_id = ?')->execute([$userId, $licenseId]);
        $this->json(['ok' => true]);
    }

    /* ─────────────────────────── activations ─────────────────────────── */

    private function listActivations(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));

        $sql = 'SELECT a.*, l.code AS license_code, l.client_name, p.name AS product_name
             FROM activations a
             JOIN licenses l ON l.id = a.license_id
             JOIN products p ON p.id = l.product_id';
        $args = [];
        if ($q !== '') {
            $sql .= ' WHERE a.domain LIKE ? OR l.code LIKE ? OR l.client_name LIKE ? OR p.name LIKE ? OR a.ip LIKE ?';
            $args = array_fill(0, 5, '%' . $q . '%');
        }
        $sql .= ' ORDER BY a.last_check_at DESC LIMIT 500';

        $st = $this->db->prepare($sql);
        $st->execute($args);

        $this->json(['data' => $st->fetchAll()]);
    }

    private function updateActivation(int $id): void
    {
        $b = $this->body();
        $status = in_array($b['status'] ?? '', ['enabled', 'disabled'], true) ? $b['status'] : null;

        if (! $status) {
            $this->json(['error' => 'Invalid status.'], 422);
        }

        $a = $this->db->prepare('SELECT license_id, domain FROM activations WHERE id = ?');
        $a->execute([$id]);
        $row = $a->fetch();

        if (! $row) {
            $this->json(['error' => 'Activation not found.'], 404);
        }

        $this->db->prepare('UPDATE activations SET status = ? WHERE id = ?')
            ->execute([$status, $id]);

        LicensePush::activation($this->db, $id, 'activation ' . $status);

        Database::log(
            $this->db,
            'admin_activation_' . ($status === 'disabled' ? 'disable' : 'enable'),
            $row ? (int) $row['license_id'] : null,
            $row['domain'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            'activation #' . $id,
            true
        );

        $this->json(['ok' => true, 'status' => $status]);
    }

    private function deleteActivation(int $id): void
    {
        LicensePush::activation($this->db, $id, 'activation revoked');
        $this->db->prepare('DELETE FROM activations WHERE id = ?')->execute([$id]);
        $this->json(['ok' => true]);
    }

    /* ─────────────────────────── server config ─────────────────────────── */

    private function getConfig(): void
    {
        $this->json([
            'data' => ServerConfig::current($this->db),
            'history' => ServerConfig::history($this->db),
        ]);
    }
    private function getConfigVersion(int $id): void
    {
        $stmt = $this->db->prepare('SELECT * FROM server_configs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (! $row) {
            $this->json(['error' => 'Config version not found.'], 404);
        }

        $this->json([
            'data' => [
                'version' => (int) $row['version'],
                'payload' => json_decode($row['payload'], true),
                'note' => $row['note'],
                'published_at' => $row['published_at'],
            ],
        ]);
    }

    private function publishConfig(): void
    {
        $b = $this->body();
        $payload = $b['payload'] ?? null;

        if (! is_array($payload) || $payload === []) {
            $this->json(['error' => 'Payload must be a non-empty JSON object.'], 422);
        }
        if (empty($payload['api_base']) || ! is_string($payload['api_base'])) {
            $this->json(['error' => 'Payload must include an "api_base" URL.'], 422);
        }
        if (! filter_var($payload['api_base'], FILTER_VALIDATE_URL)) {
            $this->json(['error' => '"api_base" must be a valid URL.'], 422);
        }

        $note = trim((string) ($b['note'] ?? '')) ?: null;
        $version = ServerConfig::publish($this->db, $payload, $note);

        Database::log($this->db, 'admin_config_publish', null, null, $_SERVER['REMOTE_ADDR'] ?? null, 'v' . $version . ($note ? ': ' . $note : ''), true);

        $this->json(['ok' => true, 'version' => $version]);
    }

    /* ─────────────────────────── logs / account ─────────────────────────── */

    private function listLogs(): void
    {
        $limit = min(200, max(10, (int) ($_GET['per'] ?? 50)));
        $join  = ' FROM activity_logs al LEFT JOIN licenses l ON l.id = al.license_id';
        $where = [];
        $params = [];

        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(l.code LIKE ? OR al.domain LIKE ? OR al.ip LIKE ? OR al.detail LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $action = trim((string) ($_GET['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'al.action = ?';
            $params[] = $action;
        }
        $result = (string) ($_GET['result'] ?? '');
        if ($result === '1' || $result === '0') {
            $where[] = 'al.success = ?';
            $params[] = (int) $result;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare('SELECT COUNT(*)' . $join . $sqlWhere);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $pages = max(1, (int) ceil($total / $limit));
        $page  = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);

        $stmt = $this->db->prepare(
            'SELECT al.*, l.code AS license_code' . $join . $sqlWhere . ' ORDER BY al.id DESC LIMIT ? OFFSET ?'
        );
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i, ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        $actions = $this->db->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

        $this->json([
            'data'    => $stmt->fetchAll(),
            'page'    => $page,
            'pages'   => $pages,
            'total'   => $total,
            'actions' => $actions,
        ]);
    }

    /* ─────────────────────────── update packages ─────────────────────────── */

    private function listUpdates(): void
    {
        $rows = $this->db->query(
            'SELECT u.id, u.update_id, u.version, u.summary, u.changelog,
                    u.has_sql, u.file_size, u.release_date, u.created_at,
                    p.name AS product_name, p.product_id AS product_code,
                    (u.file_path IS NOT NULL AND u.file_path <> \'\') AS has_file
             FROM updates u JOIN products p ON p.id = u.product_id
             ORDER BY u.release_date DESC, u.id DESC'
        )->fetchAll();

        $this->json(['data' => $rows]);
    }

    /**
     * Publish an update package — the only write path for updates.*, which
     * drives check_update / get_update_size / download_update. Multipart
     * form (not the JSON body): product_id, version, summary?, changelog?,
     * has_sql?, package (zip file, required).
     */
    private function createUpdate(): void
    {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $version   = trim((string) ($_POST['version'] ?? ''));
        $summary   = trim((string) ($_POST['summary'] ?? '')) ?: null;
        $changelog = trim((string) ($_POST['changelog'] ?? '')) ?: null;
        $hasSql    = ! empty($_POST['has_sql']) ? 1 : 0;
        $file      = $_FILES['package'] ?? null;

        if ($productId <= 0 || $version === '') {
            $this->json(['error' => 'Product and version are required.'], 422);
        }
        if (! preg_match('/^\d+(\.\d+){1,3}([.-][A-Za-z0-9]+)?$/', $version)) {
            $this->json(['error' => 'Version must look like 1.2.3 (optional pre-release suffix).'], 422);
        }

        $stmt = $this->db->prepare('SELECT id, name, product_id, latest_version FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (! $product) {
            $this->json(['error' => 'Unknown product.'], 404);
        }

        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || ! is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $this->json(['error' => 'A .zip package upload is required.'
                . (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? ' (file exceeds the server upload limit — raise upload_max_filesize/post_max_size)'
                    : '')], 422);
        }
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'zip') {
            $this->json(['error' => 'Package must be a .zip file.'], 422);
        }
        $size = (int) filesize((string) $file['tmp_name']);
        if ($size <= 0) {
            $this->json(['error' => 'Uploaded package is empty.'], 422);
        }
        if ($size > 250 * 1024 * 1024) {
            $this->json(['error' => 'Package exceeds the 250 MB cap.'], 413);
        }

        $dir = dirname(__DIR__) . '/data/updates';
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->json(['error' => 'Update storage is not writable.'], 500);
        }

        $updateId = strtoupper(bin2hex(random_bytes(4)));
        $fname    = preg_replace('/[^A-Za-z0-9._-]/', '_',
            $product['product_id'] . '-' . $version . '-' . $updateId . '.zip');
        $dest = $dir . '/' . $fname;

        if (! move_uploaded_file((string) $file['tmp_name'], $dest)) {
            $this->json(['error' => 'Could not store the uploaded package.'], 500);
        }

        $this->db->prepare(
            'INSERT INTO updates (product_id, update_id, version, summary, changelog, has_sql, file_path, file_size, release_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, date(\'now\'))'
        )->execute([$productId, $updateId, $version, $summary, $changelog, $hasSql, $dest, $size]);

        // get_config serves products.latest_version — keep it honest so the
        // bootstrap doc and check_update agree on what "newest" means.
        if (version_compare($version, (string) $product['latest_version'], '>')) {
            $this->db->prepare('UPDATE products SET latest_version = ? WHERE id = ?')
                ->execute([$version, $productId]);
        }

        Database::log($this->db, 'admin_update_publish', null, null, $_SERVER['REMOTE_ADDR'] ?? null,
            $product['name'] . ' ' . $version, true);

        $this->json(['data' => [
            'id'        => (int) $this->db->lastInsertId(),
            'update_id' => $updateId,
            'version'   => $version,
        ]], 201);
    }

    /**
     * Inspect an uploaded .zip — WordPress-style package introspection.
     * Reads the WP plugin/theme header, readme.txt and an EzIT manifest
     * (ezit.json, or any core.json up to 3 levels deep) straight from the
     * archive. Nothing is persisted except an extracted screenshot, which
     * lands in the same public uploads/catalog bucket as listing images.
     *
     * The admin UI uses the result to decide between "new product" and
     * "publish update" (manifest productId / catalog slug / name matches
     * an existing product) and to prefill every field.
     *
     * Multipart field: package. → {data: {kind, slug, name, version,
     * excerpt, description, author, changelog, has_sql, product_id,
     * screenshot_url, match}}
     */
    private function inspectPackage(): void
    {
        $file = $_FILES['package'] ?? null;
        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || ! is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            $this->json(['error' => 'A .zip package upload is required.'], 422);
        }
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'zip') {
            $this->json(['error' => 'Package must be a .zip file.'], 422);
        }
        if (! class_exists('ZipArchive')) {
            $this->json(['error' => 'PHP zip extension is not installed for this site\'s PHP version '
                . '(apt install php8.x-zip -y && systemctl restart php8.x-fpm).'], 500);
        }
        try {
            $this->json(['data' => $this->inspectZipData((string) $file['tmp_name'])]);
        } catch (Throwable $e) {
            // Undefined functions / TypeErrors land here instead of a bare 500.
            $this->json(['error' => 'Package inspect failed: ' . $e->getMessage()], 500);
        }
    }

    /** The archive walk — split out so any Error can be turned into JSON. */
    private function inspectZipData(string $tmp): array
    {
        $zip = new ZipArchive;
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Could not read the archive — is it a valid zip?');
        }

        // Inventory + package root dir (WP convention: one root folder = slug).
        $entries = [];
        $roots   = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($n === '' || str_ends_with($n, '/')) {
                continue;
            }
            $entries[] = $n;
            if (str_contains($n, '/')) {
                $seg = strstr($n, '/', true);
                $roots[$seg] = ($roots[$seg] ?? 0) + 1;
            }
        }
        arsort($roots);
        $rootDir = (string) (array_key_first($roots) ?? '');

        $read = function (string $path) use ($zip) {
            $idx = $zip->locateName($path, ZipArchive::FL_NOCASE);
            if ($idx === false) {
                return null;
            }
            // getFromIndex loads the whole entry — skip monsters (OOM is uncatchable).
            $stat = $zip->statIndex($idx);
            if ($stat !== false && ($stat['size'] ?? 0) > 10 * 1024 * 1024) {
                return null;
            }
            return (string) $zip->getFromIndex($idx);
        };

        // ── EzIT manifest: ezit.json first, then any core.json (≤3 deep) ──
        $manifest = [];
        foreach ($entries as $n) {
            if (str_contains($n, 'node_modules/')) {
                continue;
            }
            if (basename($n) === 'ezit.json' || (basename($n) === 'core.json' && substr_count($n, '/') <= 3)) {
                $m = json_decode($read($n) ?? '', true);
                if (is_array($m) && ($m['productId'] ?? $m['product_id'] ?? null)) {
                    $manifest = $m;
                    break;
                }
                $manifest = is_array($m) ? $m : [];
            }
        }

        // ── WP-style headers: theme style.css or the plugin's main .php ──
        $parse = function (string $src): array {
            $out = [];
            foreach (['Plugin Name' => 'name', 'Theme Name' => 'name',
                      'Description' => 'description', 'Version' => 'version',
                      'Author' => 'author', 'Text Domain' => 'text_domain'] as $k => $key) {
                if (preg_match('/^[ \t\/*#@]*' . preg_quote($k, '/') . ':[ \t]*([^\r\n]+)/mi', $src, $m)) {
                    $out[$key] = trim($m[1]);
                }
            }
            return $out;
        };
        $kind = 'product';
        $hdr  = [];
        $style = $read($rootDir . '/style.css');
        if ($style !== null && isset($parse($style)['name'])) {
            // style.css with a Theme Name header → a WP theme
            $h = $parse($style);
            if (preg_match('/Theme Name:/i', $style)) {
                $kind = 'theme';
                $hdr  = $h;
            }
        }
        if ($kind === 'product') {
            $scanned = 0;
            foreach ($entries as $n) {
                if ($scanned++ > 60) {
                    break;
                }
                if (substr_count($n, '/') <= 2 && str_ends_with(strtolower($n), '.php')
                    && ! str_contains($n, 'node_modules/')) {
                    $src = $read($n) ?? '';
                    if (preg_match('/Plugin Name:/i', $src)) {
                        $kind = 'plugin';
                        $hdr  = $parse($src);
                        break;
                    }
                }
            }
        }

        // ── readme.txt: stable tag, description, latest changelog block ──
        $readme = $read($rootDir . '/readme.txt') ?? $read($rootDir . '/README.txt') ?? '';
        $readmeVer = $readmeDesc = $changelog = null;
        if (preg_match('/Stable tag:[ \t]*([^\r\n]+)/i', $readme, $m)) {
            $readmeVer = trim($m[1]);
        }
        if (preg_match('/==\s*Description\s*==\s*(.+?)(?:\r?\n==|$)/s', $readme, $m)) {
            $readmeDesc = trim($m[1]);
        }
        if (preg_match('/==\s*Changelog\s*==\s*(.+?)(?:\r?\n==|$)/s', $readme, $m)) {
            // first "= x.y.z =" block only — the newest entry
            if (preg_match('/=\s*[0-9][^\s=]*\s*=\s*(.+?)(?:\r?\n=|$)/s', trim($m[1]), $mm)) {
                $changelog = trim($mm[1]);
            }
        }

        // ── screenshot → public bucket, same convention as catalogUpload ──
        $shotUrl = null;
        foreach ($entries as $n) {
            if (preg_match('/^screenshot(-\d+)?\.(png|jpe?g|webp)$/i', basename($n))) {
                $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
                $dir = dirname(__DIR__) . '/uploads/catalog';
                if (is_dir($dir) || mkdir($dir, 0755, true)) {
                    $fname = 'cat-' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $stream = $zip->getStream($n);
                    if ($stream !== false) {
                        $out = fopen($dir . '/' . $fname, 'wb');
                        if ($out !== false) {
                            stream_copy_to_stream($stream, $out);
                            fclose($out);
                            fclose($stream);
                            $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
                            $shotUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/uploads/catalog/' . $fname;
                        } else {
                            fclose($stream);
                        }
                    }
                }
                break;
            }
        }

        $hasSql = false;
        foreach ($entries as $n) {
            if (str_ends_with(strtolower($n), '.sql') && ! str_contains($n, 'node_modules/')) {
                $hasSql = true;
                break;
            }
        }

        $slug = $rootDir !== '' ? $rootDir : (string) ($hdr['text_domain'] ?? '');
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $slug), '-'));
        $name = (string) ($hdr['name'] ?? $manifest['name'] ?? '');
        if ($name === '' && $slug !== '') {
            $name = ucwords(str_replace('-', ' ', $slug));
        }
        $version = (string) ($hdr['version'] ?? $manifest['version'] ?? $readmeVer ?? '1.0.0');
        $productRef = strtoupper(trim((string) ($manifest['productId'] ?? $manifest['product_id'] ?? '')));
        $zip->close();

        // ── Match: manifest productId → catalog slug → exact name ──
        $match = null;
        if ($productRef !== '') {
            $stmt = $this->db->prepare('SELECT id, product_id, name, latest_version FROM products WHERE product_id = ?');
            $stmt->execute([$productRef]);
            $match = $stmt->fetch() ?: null;
        }
        if (! $match && $slug !== '') {
            $stmt = $this->db->prepare('SELECT p.id, p.product_id, p.name, p.latest_version FROM catalog_items c JOIN products p ON p.id = c.product_id WHERE c.slug = ?');
            $stmt->execute([$slug]);
            $match = $stmt->fetch() ?: null;
        }
        if (! $match && $name !== '') {
            $stmt = $this->db->prepare('SELECT id, product_id, name, latest_version FROM products WHERE lower(name) = lower(?)');
            $stmt->execute([$name]);
            $match = $stmt->fetch() ?: null;
        }

        $desc = (string) ($hdr['description'] ?? '');
        // mbstring isn't guaranteed (CloudPanel) — fall back to plain substr.
        $excerpt = function_exists('mb_strimwidth')
            ? mb_strimwidth($desc, 0, 160, '…')
            : (strlen($desc) > 160 ? substr($desc, 0, 157) . '…' : $desc);

        return [
            'kind'           => $kind,
            'slug'           => $slug,
            'name'           => $name,
            'version'        => $version,
            'excerpt'        => $excerpt,
            'description'    => $readmeDesc ?: ($hdr['description'] ?? null),
            'author'         => $hdr['author'] ?? null,
            'changelog'      => $changelog,
            'has_sql'        => $hasSql,
            'product_id'     => $productRef !== '' ? $productRef : null,
            'screenshot_url' => $shotUrl,
            'match'          => $match,
        ];
    }

    private function deleteUpdate(int $id): void
    {
        $stmt = $this->db->prepare(
            'SELECT u.*, p.name AS product_name FROM updates u
             JOIN products p ON p.id = u.product_id WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (! $row) {
            $this->json(['error' => 'Update not found.'], 404);
        }

        $file = (string) ($row['file_path'] ?? '');
        if ($file !== '' && is_file($file)
            && str_starts_with(realpath($file) ?: '', realpath(dirname(__DIR__) . '/data') . DIRECTORY_SEPARATOR)) {
            unlink($file);
        }
        $this->db->prepare('DELETE FROM updates WHERE id = ?')->execute([$id]);

        Database::log($this->db, 'admin_update_delete', null, null, $_SERVER['REMOTE_ADDR'] ?? null,
            $row['product_name'] . ' ' . $row['version'], true);

        $this->json(['ok' => true]);
    }

    /* ─────────────────────────── log retention ─────────────────────────── */

    private function listLogRules(): void
    {
        $rules = $this->db->query('SELECT * FROM log_rules ORDER BY position, id')->fetchAll();
        $actions = $this->db->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')
            ->fetchAll(PDO::FETCH_COLUMN);
        $counts = $this->db->query('SELECT action, COUNT(*) AS n FROM activity_logs GROUP BY action')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->json(['data' => [
            'rules'   => $rules,
            'actions' => $actions,
            'counts'  => $counts,
            'total'   => array_sum(array_map('intval', $counts ?: [])),
        ]]);
    }

    private function createLogRule(): void
    {
        $b = $this->body();
        $action = trim((string) ($b['action'] ?? ''));
        if (! preg_match('/^[\w*-]{1,64}$/', $action)) {
            $this->json(['error' => 'Pick an action (or * for a catch-all rule).'], 422);
        }
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM log_rules WHERE action = ?');
        $stmt->execute([$action]);
        if ((int) $stmt->fetchColumn() > 0) {
            $this->json(['error' => 'A rule for "' . $action . '" already exists — edit or delete it first.'], 409);
        }

        $retain = max(0, (int) ($b['retain_days'] ?? 90)); // 0 = forever
        $keep   = min(100, max(0, (int) ($b['keep_last'] ?? 0)));
        $pos    = (int) $this->db->query('SELECT COALESCE(MAX(position), -1) FROM log_rules')->fetchColumn() + 1;

        $this->db->prepare(
            'INSERT INTO log_rules (action, retain_days, keep_last, position, enabled) VALUES (?, ?, ?, ?, ?)'
        )->execute([$action, $retain, $keep, $pos, ! empty($b['enabled']) ? 1 : 0]);

        Database::log($this->db, 'admin_log_rule', null, null, $_SERVER['REMOTE_ADDR'] ?? null,
            "rule {$action}: {$retain}d" . ($keep ? " keep-last {$keep}" : ''), true);

        $this->json(['data' => ['id' => (int) $this->db->lastInsertId()]], 201);
    }

    private function updateLogRule(int $id): void
    {
        $stmt = $this->db->prepare('SELECT * FROM log_rules WHERE id = ?');
        $stmt->execute([$id]);
        if (! $stmt->fetch()) {
            $this->json(['error' => 'Rule not found.'], 404);
        }

        $b = $this->body();
        $fields = [];
        $params = [];
        if (array_key_exists('enabled', $b)) {
            $fields[] = 'enabled = ?';
            $params[] = ! empty($b['enabled']) ? 1 : 0;
        }
        if (array_key_exists('retain_days', $b)) {
            $fields[] = 'retain_days = ?';
            $params[] = max(0, (int) $b['retain_days']);
        }
        if (array_key_exists('keep_last', $b)) {
            $fields[] = 'keep_last = ?';
            $params[] = min(100, max(0, (int) $b['keep_last']));
        }
        if (! $fields) {
            $this->json(['error' => 'Nothing to update.'], 422);
        }
        $params[] = $id;
        $this->db->prepare('UPDATE log_rules SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($params);

        $this->json(['ok' => true]);
    }

    private function deleteLogRule(int $id): void
    {
        $stmt = $this->db->prepare('SELECT action FROM log_rules WHERE id = ?');
        $stmt->execute([$id]);
        $action = $stmt->fetchColumn();
        if ($action === false) {
            $this->json(['error' => 'Rule not found.'], 404);
        }
        $this->db->prepare('DELETE FROM log_rules WHERE id = ?')->execute([$id]);

        Database::log($this->db, 'admin_log_rule', null, null, $_SERVER['REMOTE_ADDR'] ?? null,
            'rule deleted: ' . $action, true);

        $this->json(['ok' => true]);
    }

    private function purgeLogs(): void
    {
        $deleted = LogRetention::run($this->db);
        @file_put_contents(dirname(__DIR__) . '/data/.logpurge', gmdate('c') . ' manual');

        Database::log($this->db, 'admin_log_purge', null, null, $_SERVER['REMOTE_ADDR'] ?? null,
            json_encode($deleted), true);

        $this->json(['ok' => true, 'deleted' => $deleted, 'total' => array_sum($deleted)]);
    }

    /* ─────────────────────────── maintenance mode ─────────────────────────── */

    /** Flag-file path — file flag works even when the DB is down. */
    private function maintenancePath(): string
    {
        return dirname(__DIR__) . '/data/.maintenance';
    }

    private function getMaintenance(): void
    {
        $this->json([
            'maintenance' => is_file($this->maintenancePath()) || ! empty($this->config['maintenance']),
            'since' => is_file($this->maintenancePath()) ? trim((string) @file_get_contents($this->maintenancePath())) : null,
        ]);
    }

    private function setMaintenance(): void
    {
        $on = ! empty($this->body()['enabled']);
        $file = $this->maintenancePath();

        if ($on) {
            file_put_contents($file, gmdate('c'));
        } elseif (is_file($file)) {
            unlink($file);
        }

        Database::log($this->db, 'admin_maintenance', null, null, $_SERVER['REMOTE_ADDR'] ?? null, $on ? 'maintenance ON' : 'maintenance OFF', true);

        // Global event — wake every activated site now so they hit the
        // API immediately (503 under maintenance) instead of waiting
        // for their hourly cron. Runs post-response via json().
        LicensePush::broadcast($this->db, 'maintenance ' . ($on ? 'ON' : 'OFF'));

        $this->json(['ok' => true, 'maintenance' => $on]);
    }

    /* ─────────────────────────── dashboard settings ─────────────────────────── */

    private const DATE_FORMATS = ['mdy', 'dmy', 'ymd'];
    private const TIME_FORMATS = ['24h', '12h'];

    private function getSettings(): void
    {
        $rows = $this->db->query('SELECT name, value FROM settings')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->json([
            'settings' => [
                'timezone'    => (string) ($rows['timezone'] ?? ''), // '' = server default
                'date_format' => (string) ($rows['date_format'] ?? 'mdy'),
                'time_format' => (string) ($rows['time_format'] ?? '24h'),
            ],
            // PHP/ server zone — what "Server default" resolves to.
            'server_tz' => date_default_timezone_get(),
        ]);
    }

    private function updateSettings(): void
    {
        $b = $this->body();

        $tz = trim((string) ($b['timezone'] ?? ''));
        if ($tz !== '' && ! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $this->json(['error' => 'Unknown timezone: ' . $tz], 422);
        }
        $df = (string) ($b['date_format'] ?? 'mdy');
        if (! in_array($df, self::DATE_FORMATS, true)) {
            $this->json(['error' => 'Invalid date_format'], 422);
        }
        $tf = (string) ($b['time_format'] ?? '24h');
        if (! in_array($tf, self::TIME_FORMATS, true)) {
            $this->json(['error' => 'Invalid time_format'], 422);
        }

        $stmt = $this->db->prepare(
            'REPLACE INTO settings (name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute(['timezone', $tz]);
        $stmt->execute(['date_format', $df]);
        $stmt->execute(['time_format', $tf]);

        Database::log($this->db, 'admin_settings', null, null, $_SERVER['REMOTE_ADDR'] ?? null, 'display settings updated', true);

        $this->getSettings(); // respond with the fresh values
    }

    private function updateAccount(): void
    {
        $b = $this->body();
        $adminId = (int) $_SESSION['admin_id'];

        if (! empty($b['password'])) {
            if (strlen($b['password']) < 10) {
                $this->json(['error' => 'Password must be at least 10 characters.'], 422);
            }
            $this->db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($b['password'], PASSWORD_DEFAULT), $adminId]);
        }
        if (! empty($b['name'])) {
            $this->db->prepare('UPDATE admins SET name = ? WHERE id = ?')
                ->execute([trim((string) $b['name']), $adminId]);
        }

        $this->json(['ok' => true]);
    }

    /* ── marketplace catalog ── */

    private const CATALOG_CATEGORIES = ['business', 'church', 'school', 'athletics', 'gov', 'dental', 'law', 'portfolio', 'generic'];
    private const CATALOG_TYPES = ['theme', 'plugin', 'product'];

    private function listCatalog(): void
    {
        $rows = $this->db->query(
            'SELECT c.*, p.name AS product_name, p.product_id AS product_ref, p.latest_version
             FROM catalog_items c JOIN products p ON p.id = c.product_id
             ORDER BY c.published DESC, p.name'
        )->fetchAll();
        // License picker for the scope field (restrict-to-licensees).
        $licenses = $this->db->query(
            'SELECT code, client_name FROM licenses ORDER BY client_name, code'
        )->fetchAll();
        $this->json(['data' => $rows, 'categories' => self::CATALOG_CATEGORIES,
                     'types' => self::CATALOG_TYPES, 'licenses' => $licenses]);
    }

    /** Shared validation → normalized field map, or exits with a 4xx. */
    private function catalogPayload(): array
    {
        $b = $this->body();
        $slug = strtolower(trim((string) ($b['slug'] ?? '')));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,118}[a-z0-9]$/', $slug)) {
            $this->json(['error' => 'Slug must be lowercase letters, digits and dashes (e.g. trezise-insurance).'], 422);
        }
        $category = trim((string) ($b['category'] ?? 'generic'));
        if (! in_array($category, self::CATALOG_CATEGORIES, true)) {
            $this->json(['error' => 'Unknown category.'], 422);
        }
        $type = trim((string) ($b['type'] ?? 'product'));
        if (! in_array($type, self::CATALOG_TYPES, true)) {
            $this->json(['error' => 'Type must be theme, plugin or product.'], 422);
        }
        $productId = (int) ($b['product_id'] ?? 0);
        $p = $this->db->prepare('SELECT id FROM products WHERE id = ?');
        $p->execute([$productId]);
        if (! $p->fetch()) {
            $this->json(['error' => 'Pick a product to list.'], 422);
        }
        $screens = $b['screenshots'] ?? [];
        if (is_string($screens)) { // comma-separated URLs from the form
            $screens = array_values(array_filter(array_map('trim', explode(',', $screens))));
        }
        if (! is_array($screens)) {
            $screens = [];
        }
        $priceRaw = $b['price'] ?? null;
        $price = ($priceRaw === '' || $priceRaw === null) ? null : (float) $priceRaw;

        // License scope — restrict visibility to sites activated under one
        // of these codes. Empty / absent = public listing.
        $scope = $b['license_scope'] ?? [];
        if (is_string($scope)) { // comma-separated codes from the form
            $scope = array_values(array_filter(array_map('trim', explode(',', $scope))));
        }
        if (! is_array($scope)) {
            $scope = [];
        }
        $scope = array_values(array_filter(array_map(
            fn ($c) => preg_match('/^[A-Z0-9-]{4,64}$/i', (string) $c) ? strtoupper(trim((string) $c)) : null,
            $scope
        )));

        return [$productId, $slug, $type, $category, $price, $screens,
            trim((string) ($b['excerpt'] ?? '')), trim((string) ($b['body'] ?? '')),
            trim((string) ($b['thumb'] ?? '')), ! empty($b['is_free']), ! empty($b['published']),
            $scope ? json_encode($scope) : null];
    }

    private function createCatalogItem(): void
    {
        [$pid, $slug, $type, $cat, $price, $screens, $ex, $body, $thumb, $free, $pub, $scope] = $this->catalogPayload();
        try {
            $this->db->prepare(
                'INSERT INTO catalog_items (product_id, slug, type, category, excerpt, body, thumb, screenshots, price, is_free, published, license_scope)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$pid, $slug, $type, $cat, $ex ?: null, $body ?: null, $thumb ?: null,
                $screens ? json_encode($screens) : null, $price, (int) $free, (int) $pub, $scope]);
        } catch (PDOException) {
            $this->json(['error' => 'That slug is already taken.'], 409);
        }
        Database::log($this->db, 'admin_catalog', null, null, $_SERVER['REMOTE_ADDR'] ?? null, 'created listing ' . $slug, true);
        $this->json(['data' => ['id' => $this->db->lastInsertId()]], 201);
    }

    private function updateCatalogItem(int $id): void
    {
        [$pid, $slug, $type, $cat, $price, $screens, $ex, $body, $thumb, $free, $pub, $scope] = $this->catalogPayload();
        try {
            $this->db->prepare(
                'UPDATE catalog_items SET product_id = ?, slug = ?, type = ?, category = ?, excerpt = ?, body = ?,
                 thumb = ?, screenshots = ?, price = ?, is_free = ?, published = ?, license_scope = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            )->execute([$pid, $slug, $type, $cat, $ex ?: null, $body ?: null, $thumb ?: null,
                $screens ? json_encode($screens) : null, $price, (int) $free, (int) $pub, $scope, $id]);
        } catch (PDOException) {
            $this->json(['error' => 'That slug is already taken.'], 409);
        }
        Database::log($this->db, 'admin_catalog', null, null, $_SERVER['REMOTE_ADDR'] ?? null, 'updated listing ' . $slug, true);
        $this->json(['ok' => true]);
    }

    /**
     * Catalog image upload — thumbs/screenshots for listings. Multipart:
     * `image` field, jpg/png/webp ≤ 5 MB. Stored under /uploads/catalog/
     * (publicly servable, PHP disabled by uploads/.htaccess); returns the
     * absolute URL the admin then pastes into thumb/screenshots fields.
     */
    private function catalogUpload(): void
    {
        $file = $_FILES['image'] ?? null;
        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || ! is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            $this->json(['error' => 'An image upload is required.'], 422);
        }
        $size = (int) filesize((string) $file['tmp_name']);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            $this->json(['error' => 'Image must be a real file up to 5 MB.'], 422);
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $this->json(['error' => 'Only .jpg, .png and .webp images are allowed.'], 422);
        }
        // Belts and braces — sniff the MIME rather than trusting the name.
        $mime = mime_content_type((string) $file['tmp_name']);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->json(['error' => 'File content is not a valid image.'], 422);
        }

        $dir = dirname(__DIR__) . '/uploads/catalog';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->json(['error' => 'Image storage is not writable.'], 500);
        }
        $fname = 'cat-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (! move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $fname)) {
            $this->json(['error' => 'Could not store the uploaded image.'], 500);
        }

        // Absolute URL — EzIT Core renders <img src> straight from the field.
        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
        $url    = $scheme . '://' . $host . $base . '/uploads/catalog/' . $fname;

        Database::log($this->db, 'admin_catalog', null, null, $_SERVER['REMOTE_ADDR'] ?? null,
            'uploaded catalog image ' . $fname, true);
        $this->json(['data' => ['url' => $url]], 201);
    }

    private function deleteCatalogItem(int $id): void
    {
        $this->db->prepare('DELETE FROM catalog_items WHERE id = ?')->execute([$id]);
        Database::log($this->db, 'admin_catalog', null, null, $_SERVER['REMOTE_ADDR'] ?? null, 'deleted listing #' . $id, true);
        $this->json(['ok' => true]);
    }

    /* ─────────────────────────── utils ─────────────────────────── */

    /** Accepts a JSON array, a JSON-encoded array string, or null. */
    private static function normalizeFeatures(mixed $features): ?string
    {
        if (is_array($features)) {
            return $features === [] ? null : json_encode(array_values($features));
        }
        if (is_string($features) && trim($features) !== '') {
            $decoded = json_decode($features, true);
            if (is_array($decoded)) {
                return $decoded === [] ? null : json_encode(array_values($decoded));
            }
        }
        return null;
    }

    public static function generateCode(): string
    {
        // LicenseBox-style: XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX (36 chars, fits client's max:36)
        $uuid = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffffffffffff)
        );

        return strtoupper($uuid);
    }

    public static function generateRecoveryCode(): string
    {
        return Database::generateRecoveryCode();
    }

    /* ─────────────────── client-site login (SSO) ───────────────────
     *
     * All routes require the 'site_login' capability — super_admin today;
     * future roles can grant narrower caps (e.g. support-mode only) via
     * ROLE_CAPS without touching these handlers.
     */

    /** GET site-users?activation=N — fetch the site's user list. */
    private function siteUsers(): void
    {
        $this->requireCap('site_login');

        $target = SiteLogin::target($this->db, (int) ($_GET['activation'] ?? 0));
        if (! $target) {
            $this->json(['error' => 'Unknown or unreachable activation.'], 404);
        }
        [$site, $apiKey] = $target;

        $res = SiteLogin::users($site, $apiKey);
        if ($res['error'] !== null) {
            $this->json(['error' => $res['error']], 502);
        }

        $this->json(['data' => [
            'users' => $res['users'],
            'roles' => $res['roles'] ?? new stdClass, // slug => label ({} when site doesn't send them)
        ]]);
    }

    /**
     * GET site-login-capable?activation=N — probe whether the site runs
     * EzIT Core >=1.3.0 (sso routes live). The UI hides the "Log in"
     * button when this reports false, so a probe is cheap: unsigned GET,
     * route-existence check, no key sent.
     */
    private function siteLoginCapable(): void
    {
        $this->requireCap('site_login');

        $target = SiteLogin::target($this->db, (int) ($_GET['activation'] ?? 0));
        if (! $target) {
            $this->json(['data' => ['capable' => false]]);
        }
        [$site] = $target;

        $this->json(['data' => ['capable' => SiteLogin::capable($site)]]);
    }

    /**
     * POST site-login — { activation_id, mode, … } → { login_url }.
     *
     * modes:
     *   first_admin — one-time URL for the site's first administrator
     *   user        — one-time URL for user_id ("log in as" any account)
     *   credentials — verify username+password on the site; optional
     *                 credential_id resolves a remembered login, optional
     *                 remember+label encrypts and stores it
     *   provision   — create/reset a WP user (role, default administrator)
     */
    private function siteLogin(): void
    {
        $this->requireCap('site_login');

        $b = $this->body();
        $activationId = (int) ($b['activation_id'] ?? 0);
        $mode = (string) ($b['mode'] ?? 'first_admin');

        $target = SiteLogin::target($this->db, $activationId);
        if (! $target) {
            $this->json(['error' => 'Unknown or unreachable activation.'], 404);
        }
        [$site, $apiKey, $domain] = $target;

        $params = match ($mode) {
            'first_admin' => ['action' => 'token', 'mode' => 'first_admin'],
            'user' => ['action' => 'token', 'mode' => 'user', 'user_id' => (int) ($b['user_id'] ?? 0)],
            'credentials' => $this->credentialParams($b, $activationId),
            'provision' => $this->provisionParams($b),
            default => $this->json(['error' => 'Unknown login mode.'], 422),
        };

        // Remember-me vault — store an encrypted copy after a successful
        // call so we never park a wrong password.
        $remember = ! empty($b['remember'])
            && in_array($mode, ['credentials', 'provision'], true);
        $rememberUser = trim((string) ($b['username'] ?? ''));
        $rememberPass = (string) ($b['password'] ?? '');

        $res = SiteLogin::token($site, $apiKey, $params);
        if ($res['error'] !== null) {
            Database::log($this->db, 'admin_site_login', null, $domain, null, $mode . ' failed: ' . $res['error'], false);
            $this->json(['error' => $res['error']], 502);
        }

        if ($remember && $rememberUser !== '' && $rememberPass !== '') {
            $this->db->prepare(
                'INSERT INTO site_logins (activation_id, label, username, password_enc) VALUES (?, ?, ?, ?)
                 ON CONFLICT (activation_id, username) DO UPDATE SET label = excluded.label, password_enc = excluded.password_enc'
            )->execute([
                $activationId,
                trim((string) ($b['label'] ?? '')),
                $rememberUser,
                SiteLogin::seal($this->config['server_secret'], $rememberPass),
            ]);
        }

        Database::log($this->db, 'admin_site_login', null, $domain, null,
            $mode . ' by admin ' . ($_SESSION['admin_id'] ?? '?'), true);

        $this->json(['data' => ['login_url' => $res['login_url']]]);
    }

    /** @return array<string,mixed> params for mode=credentials (exits 4xx on bad input). */
    private function credentialParams(array $b, int $activationId): array
    {
        // Saved credential wins over typed fields when an id is passed.
        if (! empty($b['credential_id'])) {
            $stmt = $this->db->prepare(
                'SELECT * FROM site_logins WHERE id = ? AND activation_id = ?'
            );
            $stmt->execute([(int) $b['credential_id'], $activationId]);
            $row = $stmt->fetch();
            if (! $row) {
                $this->json(['error' => 'Saved login not found.'], 404);
            }
            $pass = SiteLogin::open($this->config['server_secret'], (string) $row['password_enc']);
            if ($pass === null) {
                $this->json(['error' => 'Saved login cannot be decrypted — re-save it.'], 500);
            }
            return ['action' => 'token', 'mode' => 'credentials',
                    'username' => $row['username'], 'password' => $pass];
        }

        $user = trim((string) ($b['username'] ?? ''));
        $pass = (string) ($b['password'] ?? '');
        if ($user === '' || $pass === '') {
            $this->json(['error' => 'Username and password are required.'], 422);
        }

        return ['action' => 'token', 'mode' => 'credentials',
                'username' => $user, 'password' => $pass];
    }

    /** @return array<string,mixed> params for mode=provision. */
    private function provisionParams(array $b): array
    {
        $user = trim((string) ($b['username'] ?? ''));
        $pass = (string) ($b['password'] ?? '');
        if ($user === '' || strlen($pass) < 8) {
            $this->json(['error' => 'Username required; password must be at least 8 characters.'], 422);
        }

        return ['action' => 'provision',
                'username' => $user,
                'password' => $pass,
                'email'    => trim((string) ($b['email'] ?? '')),
                // Role is client-resolved — 'administrator' today, custom
                // roles (support, staff…) pass straight through later.
                'role'     => trim((string) ($b['role'] ?? 'administrator')) ?: 'administrator'];
    }

    /** GET site-logins?activation=N — remembered usernames (never passwords). */
    private function listSiteLogins(): void
    {
        $this->requireCap('site_login');
        $activationId = (int) ($_GET['activation'] ?? 0);

        $stmt = $this->db->prepare(
            'SELECT id, activation_id, label, username, created_at
             FROM site_logins WHERE activation_id = ? ORDER BY username'
        );
        $stmt->execute([$activationId]);

        $this->json(['data' => $stmt->fetchAll()]);
    }

    /** POST site-logins — save a credential without logging in. */
    private function createSiteLogin(): void
    {
        $this->requireCap('site_login');
        $b = $this->body();

        $activationId = (int) ($b['activation_id'] ?? 0);
        $username = trim((string) ($b['username'] ?? ''));
        $password = (string) ($b['password'] ?? '');
        if (! SiteLogin::target($this->db, $activationId) || $username === '' || $password === '') {
            $this->json(['error' => 'activation_id, username and password are required.'], 422);
        }

        $this->db->prepare(
            'INSERT INTO site_logins (activation_id, label, username, password_enc) VALUES (?, ?, ?, ?)
             ON CONFLICT (activation_id, username) DO UPDATE SET label = excluded.label, password_enc = excluded.password_enc'
        )->execute([
            $activationId,
            trim((string) ($b['label'] ?? '')),
            $username,
            SiteLogin::seal($this->config['server_secret'], $password),
        ]);

        $this->json(['data' => ['id' => (int) $this->db->lastInsertId()]], 201);
    }

    /** POST site-logins/{id}/reveal — decrypt for display (audited). */
    private function revealSiteLogin(int $id): void
    {
        $this->requireCap('site_login');

        $stmt = $this->db->prepare(
            'SELECT s.*, a.domain FROM site_logins s JOIN activations a ON a.id = s.activation_id WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (! $row) {
            $this->json(['error' => 'Saved login not found.'], 404);
        }

        $plain = SiteLogin::open($this->config['server_secret'], (string) $row['password_enc']);
        if ($plain === null) {
            $this->json(['error' => 'Cannot decrypt — re-save this login.'], 500);
        }

        Database::log($this->db, 'admin_site_login_reveal', null, (string) $row['domain'], null,
            'revealed ' . $row['username'] . ' (admin ' . ($_SESSION['admin_id'] ?? '?') . ')', true);

        $this->json(['data' => ['username' => $row['username'], 'password' => $plain]]);
    }

    private function deleteSiteLogin(int $id): void
    {
        $this->requireCap('site_login');
        $this->db->prepare('DELETE FROM site_logins WHERE id = ?')->execute([$id]);
        $this->json(['ok' => true]);
    }

    private function body(): array
    {
        static $body = null;
        if ($body === null) {
            $body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
        }

        return $body;
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $body = json_encode($data);
        header('Content-Length: ' . strlen($body));
        echo $body;
        session_write_close(); // release the session lock before post-response work
        self::closeConnection();
        LicensePush::flush($this->db, (int) ($this->config['push_max_sites'] ?? 0) ?: null);
        exit;
    }

    /** End the HTTP exchange so the browser isn't held by queued pushes. */
    private static function closeConnection(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        ignore_user_abort(true);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
    }
}
