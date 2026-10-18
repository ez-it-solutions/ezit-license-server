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
            $resource === 'updates' && $method === 'GET' && ! $id => $this->listUpdates(),
            $resource === 'updates' && $method === 'POST' && ! $id => $this->createUpdate(),
            $resource === 'updates' && $method === 'DELETE' && $id => $this->deleteUpdate((int) $id),
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
            $path === 'maintenance' && $method === 'GET' => $this->getMaintenance(),
            $path === 'maintenance' && $method === 'PUT' => $this->setMaintenance(),
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
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        Database::log($this->db, 'admin_login', null, null, $_SERVER['REMOTE_ADDR'] ?? null, $email, true);

        $this->json([
            'admin' => ['id' => $admin['id'], 'name' => $admin['name'], 'email' => $admin['email']],
            'csrf' => $_SESSION['csrf'],
        ]);
    }

    private function sessionInfo(): void
    {
        if (empty($_SESSION['admin_id'])) {
            $this->json(['authenticated' => false]);
        }

        $stmt = $this->db->prepare('SELECT id, name, email FROM admins WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);

        $this->json([
            'authenticated' => true,
            'admin' => $stmt->fetch(),
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
        $productId = trim((string) ($b['product_id'] ?? ''));
        $name = trim((string) ($b['name'] ?? ''));

        if ($productId === '' || $name === '') {
            $this->json(['error' => 'Product ID and name are required.'], 422);
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
        $this->json(['data' => ['id' => $this->db->lastInsertId(), 'api_key' => $apiKey, 'recovery_code' => $recoveryCode]], 201);
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
