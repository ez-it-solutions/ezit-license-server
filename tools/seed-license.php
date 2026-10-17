<?php

declare(strict_types=1);

/**
 * CLI helper — create a license directly in the license-server DB.
 *
 * Usage:
 *   php tools/seed-license.php
 *   php tools/seed-license.php "Client Name" client@example.com 5
 *
 * Args: [client_name] [client_email] [max_activations] [product_id]
 */

$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../src/Database.php';

use EzIT\LicenseServer\Database;

$pdo = Database::connect($config);
Database::migrate($pdo);

$products = $pdo->query('SELECT id, product_id, name, api_key, status FROM products')->fetchAll(PDO::FETCH_ASSOC);

if (! $products) {
    fwrite(STDERR, "No products found — create one in the admin dashboard first.\n");
    exit(1);
}

echo "Products:\n";
foreach ($products as $p) {
    echo "  [{$p['id']}] {$p['name']} ({$p['product_id']}) api_key={$p['api_key']} status={$p['status']}\n";
}

$clientName = $argv[1] ?? 'Legendary 3D Prints';
$clientEmail = $argv[2] ?? 'chris@ez-it-solutions.com';
$maxActivations = max(1, (int) ($argv[3] ?? 5));
$productPk = (int) ($argv[4] ?? $products[0]['id']);

// Envato-style purchase code: 4 groups of 4 hex chars = 19 chars (passes min:19/max:36)
$code = implode('-', array_map(
    fn () => strtoupper(bin2hex(random_bytes(2))),
    range(1, 4)
));

$pdo->prepare(
    'INSERT INTO licenses (product_id, code, client_name, client_email, status, max_activations, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $productPk,
    $code,
    $clientName,
    $clientEmail,
    'active',
    $maxActivations,
    'Seeded via tools/seed-license.php',
]);

echo "\nLicense created:\n";
echo "  code      : {$code}\n";
echo "  client    : {$clientName} <{$clientEmail}>\n";
echo "  max_sites : {$maxActivations}\n";
echo "  status    : active\n";
echo "\nIn the CMS activation form use:\n";
echo "  Username on Envato : {$clientEmail} (any value works — it is stored as licensed_to)\n";
echo "  Purchase code      : {$code}\n";
