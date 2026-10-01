<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooSchedulerHealth
{
    public const LAST_TICK = 'ncwoo_scheduler_last_tick';

    public static function record_tick(): void
    {
        // A scheduler heartbeat is not proof that a delivery succeeded.
        update_option(self::LAST_TICK, time(), false);
    }

    public static function status(string $mode, bool $disabled, int $next, int $last, int $interval, int $now): string
    {
        $grace = max(120, min(3600, $interval) * 2);
        $recent = $last > 0 && $last <= $now && $now - $last <= $grace;
        if ($mode !== 'cron_module') {
            return $recent ? 'running' : 'external';
        }
        if ($next <= 0) {
            return 'missing';
        }
        if ($next < $now - $grace) {
            return 'overdue';
        }
        if ($recent) {
            return 'running';
        }
        return $disabled ? 'disabled' : 'unverified';
    }
}
