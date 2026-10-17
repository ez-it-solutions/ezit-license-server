<?php

declare(strict_types=1);

/**
 * CLI helper — dump recent activity log entries.
 * Usage: php tools/view-log.php [limit]
 */

$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../src/Database.php';

use EzIT\LicenseServer\Database;

$pdo = Database::connect($config);
$limit = max(1, (int) ($argv[1] ?? 20));

$rows = $pdo->query(
    "SELECT a.action, l.code AS license_code, a.domain, a.ip, a.detail, a.success, a.created_at
     FROM activity_logs a LEFT JOIN licenses l ON l.id = a.license_id
     ORDER BY a.id DESC LIMIT {$limit}"
)->fetchAll(PDO::FETCH_ASSOC);

if (! $rows) {
    echo "No log entries.\n";
    exit;
}

foreach ($rows as $r) {
    printf(
        "%s  %-22s code=%-20s domain=%-20s ip=%-15s ok=%d  %s\n",
        $r['created_at'],
        $r['action'],
        $r['license_code'] ?? '-',
        $r['domain'] ?? '-',
        $r['ip'] ?? '-',
        $r['success'],
        $r['detail'] ?? ''
    );
}
