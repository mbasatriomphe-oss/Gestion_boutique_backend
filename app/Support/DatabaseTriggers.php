<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class DatabaseTriggers
{
    public static function usesApplicationFallback(): bool
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        if (str_ends_with(strtolower((string) $connection->getConfig('host')), '.tidbcloud.com')) {
            return true;
        }

        return str_contains(strtolower($connection->getServerVersion()), 'tidb');
    }

    public static function unprepared(string $sql): bool
    {
        // Keep the rest of mixed migrations (tables, views, data) running.
        if (preg_match('/^\s*(?:CREATE|DROP)\s+TRIGGER\b/i', $sql) && self::usesApplicationFallback()) {
            return true;
        }

        return DB::unprepared($sql);
    }
}
