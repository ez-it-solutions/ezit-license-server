<?php

declare(strict_types=1);

/**
 * Simulate the exact HTTP call Botble's Core::activateLicense() makes.
 * Usage: php tools/test-activate.php [license_code] [client_name]
 */

$licenseCode = $argv[1] ?? 'FA1B-5434-0CE4-2D03';
$clientName = $argv[2] ?? 'chrishultberg';

$config = require __DIR__ . '/../config/config.php';

$body = json_encode([
    'product_id' => '5F061D22',
    'license_code' => $licenseCode,
    'client_name' => $clientName,
    'verify_type' => 'envato',
]);

$ch = curl_init('http://127.0.0.1:8085/api/activate_license');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'LB-API-KEY: b6b658094eec7865dce5',
        'LB-URL: http://127.0.0.1:8001',
        'LB-IP: 127.0.0.1',
        'LB-LANG: english',
    ],
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

echo "HTTP {$status}\n";
echo "Request body : {$body}\n";
echo "Response     : {$response}\n";
