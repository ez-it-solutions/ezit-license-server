<?php

declare(strict_types=1);

namespace EzIT\LicenseServer;

use PDO;

/**
 * Thunderbird-mail-filter style retention for activity_logs.
 *
 * Each rule targets one action name (or '*' as catch-all) and says how
 * long matching rows live. Every action is governed by its most specific
 * rule — an action-named rule beats '*'; an action with no rule at all
 * is kept forever.
 *
 * retain_days = 0  → keep forever (never purged)
 * keep_last   > 0  → the newest N rows PER (license × domain) group are
 *                    pinned — a site always keeps its last-known record
 *                    for diagnostics even when the row is expired.
 */
final class LogRetention
{
    /** Daily guard — file flag so the check costs no DB round-trip. */
    public static function maybeRun(PDO $db, string $dataDir): void
    {
        $flag = $dataDir . '/.logpurge';
        if (is_file($flag) && filemtime($flag) > time() - 86400) {
            return;
        }
        $deleted = self::run($db);
        @file_put_contents($flag, gmdate('c') . ' deleted=' . array_sum($deleted));
        if ($deleted) {
            Database::log($db, 'log_purge', null, null, null, json_encode($deleted), true);
        }
    }

    /**
     * Run one purge pass. Returns {action: rows_deleted}.
     */
    public static function run(PDO $db): array
    {
        $rules = $db->query(
            'SELECT * FROM log_rules WHERE enabled = 1 ORDER BY position, id'
        )->fetchAll();
        if (! $rules) {
            return [];
        }

        $actions = $db->query('SELECT DISTINCT action FROM activity_logs')->fetchAll(PDO::FETCH_COLUMN);
        $deleted = [];

        foreach ($actions as $action) {
            $rule = self::ruleFor((string) $action, $rules);
            if (! $rule || (int) $rule['retain_days'] === 0) {
                continue; // no rule or keep-forever
            }
            $n = self::apply($db, $rule);
            if ($n > 0) {
                $deleted[$action] = $n;
            }
        }
        return $deleted;
    }

    /** First action-named match wins; '*' is the fallback for everything else. */
    private static function ruleFor(string $action, array $rules): ?array
    {
        $fallback = null;
        foreach ($rules as $r) {
            if ($r['action'] === '*') {
                $fallback ??= $r;
                continue;
            }
            if ($r['action'] === $action) {
                return $r;
            }
        }
        return $fallback;
    }

    private static function apply(PDO $db, array $rule): int
    {
        $action = (string) $rule['action'];
        $cutoff = '-' . max(1, (int) $rule['retain_days']) . ' days';
        $keep   = max(0, (int) $rule['keep_last']);
        $where  = $action === '*' ? '1=1' : 'action = :action';

        if ($keep === 0) {
            $stmt = $db->prepare(
                "DELETE FROM activity_logs WHERE {$where} AND created_at < datetime('now', :cutoff)"
            );
        } else {
            // Window-ranked groups: every (action × license × domain) keeps
            // its newest `keep` rows — pinned regardless of age.
            $stmt = $db->prepare(
                "DELETE FROM activity_logs
                 WHERE {$where} AND created_at < datetime('now', :cutoff)
                   AND id NOT IN (
                       SELECT id FROM (
                           SELECT id, ROW_NUMBER() OVER (
                               PARTITION BY action, COALESCE(license_id, -1), COALESCE(domain, '')
                               ORDER BY id DESC
                           ) AS rn
                           FROM activity_logs WHERE {$where}
                       ) ranked WHERE ranked.rn <= :keep
                   )"
            );
            $stmt->bindValue(':keep', $keep, PDO::PARAM_INT);
        }
        if ($action !== '*') {
            $stmt->bindValue(':action', $action);
        }
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** Seed starter rules — only when the table is empty (first migrate). */
    public static function seed(PDO $db): void
    {
        if ((int) $db->query('SELECT COUNT(*) FROM log_rules')->fetchColumn() > 0) {
            return;
        }
        $ins = $db->prepare(
            'INSERT INTO log_rules (action, retain_days, keep_last, position) VALUES (?, ?, ?, ?)'
        );
        foreach (self::defaults() as $pos => [$action, $days, $keep]) {
            $ins->execute([$action, $days, $keep, $pos]);
        }
    }

    /**
     * Defaults tuned to fill-rate: high-volume verify/connect purge fast
     * but pin each site's newest row; lifecycle events keep forever.
     */
    private static function defaults(): array
    {
        return [
            // action            days  keep_last
            ['verify',            7,   1], // noisiest — keep last check-in per site
            ['connect',           7,   1],
            ['config_fetch',     14,   0],
            ['update_check',     30,   0],
            ['push',             30,   0],
            ['access_login',     30,   1],
            ['download',         90,   0], // license event — audit longer
            ['activate',          0,   0], // forever — the activation record itself
            ['deactivate',        0,   0], // forever — revocations matter
            ['admin_login_fail',180,   0], // security trail — longest retention
            ['*',                90,   0], // catch-all for admin_* + future actions
        ];
    }
}
