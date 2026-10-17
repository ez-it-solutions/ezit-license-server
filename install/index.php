<?php

declare(strict_types=1);

/**
 * First-run installer for the Ez IT Solutions License Server.
 * Writes config/config.php, creates the schema, creates the admin,
 * seeds the Botble CMS product, then prints the api_key for core.json.
 */

use EzIT\LicenseServer\Database;

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
$alreadyInstalled = is_file($configFile);

$error = null;
$done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $alreadyInstalled) {
    try {
        $adminName = trim((string) ($_POST['admin_name'] ?? ''));
        $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
        $adminPass = (string) ($_POST['admin_password'] ?? '');
        $driver = ($_POST['db_driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';

        if ($adminName === '' || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminPass) < 10) {
            throw new RuntimeException('Name, a valid email and a password of 10+ characters are required.');
        }

        $db = ['driver' => 'sqlite', 'sqlite_path' => $root . '/data/license-server.sqlite'];
        if ($driver === 'mysql') {
            $db = [
                'driver' => 'mysql',
                'host' => trim((string) $_POST['db_host']),
                'port' => (int) ($_POST['db_port'] ?: 3306),
                'database' => trim((string) $_POST['db_name']),
                'username' => trim((string) $_POST['db_user']),
                'password' => (string) $_POST['db_pass'],
                'charset' => 'utf8mb4',
            ];
        }

        $secret = bin2hex(random_bytes(32));
        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $appUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'license.ez-it-solutions.com');

        $config = [
            'db' => $db,
            'server_secret' => $secret,
            'app_name' => 'Ez IT Solutions License Server',
            'app_url' => $appUrl,
            'rate_limit_per_minute' => 60,
            'session_name' => 'ezitls',
            'notify_email' => $adminEmail,
        ];

        // Test connection + migrate before writing config
        $pdo = Database::connect(['db' => $db]);
        Database::migrate($pdo);

        $export = var_export($config, true);
        if (file_put_contents($configFile, '<?php' . "\n\nreturn " . $export . ";\n") === false) {
            throw new RuntimeException('Could not write config/config.php — check directory permissions.');
        }

        // Admin user
        $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$adminName, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);

        // Seed the Botble CMS product so existing sites work unchanged
        $productApiKey = bin2hex(random_bytes(10));
        $pdo->prepare(
            'INSERT INTO products (product_id, name, api_key, latest_version) VALUES (?, ?, ?, ?)'
        )->execute(['5F061D22', 'Botble CMS', $productApiKey, '1.25.1']);

        $done = ['api_key' => $productApiKey, 'product_id' => '5F061D22', 'app_url' => $appUrl];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install — Ez IT Solutions License Server</title>
<style>
    :root { --green: #76b900; --bg: #0b0e0b; --panel: #121612; --border: #22301e; --text: #e8f0e3; --muted: #8fa386; }
    * { box-sizing: border-box; margin: 0; }
    body { background: radial-gradient(1200px 600px at 70% -10%, #1a2b12 0%, var(--bg) 55%); color: var(--text); font: 15px/1.5 "Segoe UI", system-ui, sans-serif; min-height: 100vh; display: grid; place-items: center; padding: 2rem; }
    .card { width: 100%; max-width: 560px; background: var(--panel); border: 1px solid var(--border); border-radius: 14px; padding: 2.25rem; box-shadow: 0 30px 80px rgba(0,0,0,.5); }
    .logo { display: flex; align-items: center; gap: .6rem; margin-bottom: 1.5rem; }
    .logo-mark { width: 34px; height: 34px; border-radius: 8px; background: linear-gradient(135deg, var(--green), #4e8a00); display: grid; place-items: center; font-weight: 800; color: #0b0e0b; }
    .logo span { font-weight: 700; letter-spacing: .5px; } .logo small { color: var(--muted); display: block; font-weight: 400; }
    h1 { font-size: 1.3rem; margin-bottom: .35rem; } p.sub { color: var(--muted); margin-bottom: 1.5rem; }
    label { display: block; font-size: .82rem; color: var(--muted); margin: .9rem 0 .3rem; }
    input, select { width: 100%; background: #0d110d; border: 1px solid var(--border); color: var(--text); border-radius: 8px; padding: .6rem .75rem; font: inherit; }
    input:focus, select:focus { outline: 2px solid var(--green); outline-offset: -1px; border-color: transparent; }
    button { margin-top: 1.4rem; width: 100%; background: var(--green); color: #0b0e0b; font-weight: 700; border: 0; border-radius: 8px; padding: .75rem; font: inherit; cursor: pointer; }
    button:hover { filter: brightness(1.08); }
    .error { background: #3a1414; border: 1px solid #7a2b2b; color: #f0b8b8; padding: .7rem .9rem; border-radius: 8px; margin-bottom: 1rem; }
    .success { background: #14280f; border: 1px solid #3c6b1f; padding: 1rem; border-radius: 8px; margin-top: 1rem; }
    .success code { display: block; background: #0d110d; border: 1px dashed var(--green); padding: .7rem; border-radius: 6px; margin-top: .4rem; word-break: break-all; color: var(--green); font-size: .85rem; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; }
    #mysql-fields { display: none; }
    .note { font-size: .8rem; color: var(--muted); margin-top: 1.2rem; }
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <div class="logo-mark">EZ</div>
        <div><span>EZ IT SOLUTIONS</span><small>License Server Installer</small></div>
    </div>

    <?php if ($done): ?>
        <h1>Installation complete</h1>
        <p class="sub">Your license server is live. Do these two things next:</p>
        <div class="success">
            1. In every site's <b>platform/core/core.json</b>, set:
            <code>"apiUrl": "<?= htmlspecialchars(rtrim($done['app_url'], '/')) ?>",
"apiKey": "<?= $done['api_key'] ?>"</code>
            2. Delete or restrict the <b>/install</b> directory on the server, then log in at <a style="color:var(--green)" href="/">the dashboard</a>.
        </div>
        <div class="success">
            License codes you issue in the dashboard activate sites for product
            <code><?= $done['product_id'] ?></code>
        </div>
    <?php elseif ($alreadyInstalled): ?>
        <h1>Already installed</h1>
        <p class="sub">This server is already configured. Remove the <b>install/</b> directory for security.</p>
        <a href="/"><button type="button">Go to dashboard</button></a>
    <?php else: ?>
        <h1>Set up your license server</h1>
        <p class="sub">One-time setup for license.ez-it-solutions.com</p>

        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="post">
            <div class="grid2">
                <div><label>Your name</label><input name="admin_name" value="Chris Hultberg" required></div>
                <div><label>Email (login)</label><input type="email" name="admin_email" placeholder="chris@ez-it-solutions.com" required></div>
            </div>
            <label>Password (min 10 chars)</label>
            <input type="password" name="admin_password" minlength="10" required>

            <label>Database</label>
            <select name="db_driver" onchange="document.getElementById('mysql-fields').style.display = this.value==='mysql' ? 'block':'none'">
                <option value="sqlite">SQLite — simplest, zero config</option>
                <option value="mysql">MySQL</option>
            </select>

            <div id="mysql-fields">
                <div class="grid2">
                    <div><label>Host</label><input name="db_host" value="localhost"></div>
                    <div><label>Port</label><input name="db_port" value="3306"></div>
                </div>
                <div class="grid2">
                    <div><label>Database</label><input name="db_name"></div>
                    <div><label>Username</label><input name="db_user"></div>
                </div>
                <label>Password</label><input type="password" name="db_pass">
            </div>

            <button type="submit">Install License Server</button>
        </form>
        <p class="note">Seeds product <b>5F061D22</b> (Botble CMS) so existing client sites work unchanged after repointing <b>core.json</b>.</p>
    <?php endif; ?>
</div>
</body>
</html>
