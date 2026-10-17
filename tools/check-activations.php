<?php

declare(strict_types=1);

/**
 * Dump activations table columns + rows.
 * Usage: php tools/check-activations.php
 */

require __DIR__ . '/../src/Database.php';

use EzIT\LicenseServer\Database;

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config);
Database::migrate($pdo);

echo "-- columns --\n";
foreach ($pdo->query('PRAGMA table_info(activations)') as $col) {
    echo $col['name'] . ' ' . $col['type'] . "\n";
}

echo "-- rows --\n";
foreach ($pdo->query('SELECT id, license_id, domain, status, activated_at FROM activations') as $row) {
    echo "#{$row['id']}  license={$row['license_id']}  domain={$row['domain']}  status={$row['status']}  at={$row['activated_at']}\n";
}
