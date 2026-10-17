<?php

declare(strict_types=1);

namespace EzIT\LicenseServer;

use PDO;

/**
 * Versioned server bootstrap configuration.
 *
 * EzIT Core (and anything else site-side) calls /api/get_config and
 * receives this JSON doc — it tells the plugin where the API lives,
 * which endpoints exist, and any service notices. Each publish is a
 * new immutable row: full edit history, easy rollback (re-publish an
 * older payload), and a monotonically increasing `config_version`
 * clients compare against their cached copy.
 */
final class ServerConfig
{
    /** Latest published config, or null if none yet. */
    public static function current(PDO $db): ?array
    {
        $row = $db->query(
            'SELECT * FROM server_configs ORDER BY version DESC LIMIT 1'
        )->fetch();

        if (! $row) {
            return null;
        }

        return [
            'version' => (int) $row['version'],
            'payload' => json_decode($row['payload'], true) ?: [],
            'note' => $row['note'],
            'published_at' => $row['published_at'],
        ];
    }

    /** Publish a new immutable version. Returns the version number. */
    public static function publish(PDO $db, array $payload, ?string $note = null): int
    {
        $version = (int) $db->query('SELECT COALESCE(MAX(version), 0) + 1 FROM server_configs')->fetchColumn();

        $db->prepare('INSERT INTO server_configs (version, payload, note) VALUES (?, ?, ?)')
            ->execute([$version, json_encode($payload, JSON_UNESCAPED_SLASHES), $note]);

        return $version;
    }

    /** Version history, newest first. */
    public static function history(PDO $db): array
    {
        return $db->query(
            'SELECT id, version, note, published_at FROM server_configs ORDER BY version DESC LIMIT 50'
        )->fetchAll();
    }

    /**
     * Seed document — written on first migrate so the endpoint always
     * has something coherent to serve. Edit from the admin panel; do
     * not grow this file by hand.
     */
    public static function defaultDoc(): array
    {
        return [
            'api_base' => 'https://license.ez-it-solutions.com/api/',
            'endpoints' => [
                'check_connection'  => 'check_connection_ext',
                'activate_license'  => 'activate_license',
                'access_login'      => 'access_login',
                'verify_license'    => 'verify_license',
                'deactivate_license'=> 'deactivate_license',
                'check_update'      => 'check_update',
                'get_update_size'   => 'get_update_size/{update_id}',
                'download_update'   => 'download_update/main/{update_id}',
                'get_config'        => 'get_config',
                'catalog'           => 'catalog',
            ],
            'services' => [
                'ezops'       => 'https://ops.ez-it-solutions.com', // portal / future API host
                'marketplace' => null, // URL when marketplace ships
                'support'     => null,
                'billing'     => 'https://billing.ez-it-solutions.com',
            ],
            'notices' => [], // [{level: info|warning|critical, message, until}]
        ];
    }
}
