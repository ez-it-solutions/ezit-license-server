<?php

declare(strict_types=1);

namespace EzIT\LicenseServer;

/**
 * License certificate ("lic_response") issued on activation and stored
 * verbatim by the CMS in storage/.license. The CMS never parses it — it
 * echoes it back on verify/deactivate/download calls.
 *
 * Format: <64-char HMAC-SHA256 hex><base64url(JSON payload)>
 *
 * The HMAC covers the payload and is keyed with the server secret +
 * product API key, so certificates cannot be forged or moved between
 * products without detection.
 */
final class Certificate
{
    public static function issue(
        string $serverSecret,
        string $productApiKey,
        string $productId,
        string $licenseCode,
        string $clientName,
        string $domain,
        ?string $ip,
        ?string $expiresAt,
        array $extra = []
    ): string {
        $payload = array_merge([
            'license_code' => $licenseCode,
            'product_id'   => $productId,
            'client_name'  => $clientName,
            'domain'       => $domain,
            'ip'           => $ip,
            'activated_at' => gmdate('Y-m-d H:i:s'),
            'expires_at'   => $expiresAt,
            'issuer'       => 'license.ez-it-solutions.com',
        ], $extra);

        $payloadB64 = self::b64urlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return self::sign($serverSecret, $productApiKey, $payloadB64) . $payloadB64;
    }

    /**
     * Parse + verify a certificate. Returns payload array or null if
     * malformed / signature mismatch.
     */
    public static function parse(string $serverSecret, string $productApiKey, string $certificate): ?array
    {
        $certificate = trim($certificate);

        if (strlen($certificate) <= 64) {
            return null;
        }

        $signature = substr($certificate, 0, 64);
        $payloadB64 = substr($certificate, 64);

        if (! hash_equals(self::sign($serverSecret, $productApiKey, $payloadB64), $signature)) {
            return null;
        }

        $json = self::b64urlDecode($payloadB64);
        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private static function sign(string $serverSecret, string $productApiKey, string $payloadB64): string
    {
        return hash_hmac('sha256', $payloadB64, $serverSecret . '|' . $productApiKey);
    }

    private static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
