<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('db:diagnose', function () {
    $name = config('database.default');
    $connection = config("database.connections.$name", []);
    $this->line('Database diagnostic: '.json_encode([
        'connection' => $name,
        'host' => $connection['host'] ?? null,
        'port' => $connection['port'] ?? null,
        'database' => $connection['database'] ?? null,
        'password_present' => !empty($connection['password']),
        'db_url_present' => !empty($connection['url']),
    ]));
    try {
        DB::connection()->select('SELECT 1');
        $this->info('Database connection: OK');
        return 0;
    } catch (Throwable $error) {
        $info = $error instanceof PDOException ? ($error->errorInfo ?? []) : [];
        $message = strtolower($error->getMessage());
        $reason = match (true) {
            str_contains($message, 'getaddrinfo') => 'DNS: database hostname cannot be resolved',
            str_contains($message, 'could not find driver') => 'PDO MySQL driver missing from image',
            str_contains($message, 'access denied') => 'TiDB username/password rejected or access denied',
            str_contains($message, 'unknown database') => 'Configured database does not exist',
            str_contains($message, 'ssl'), str_contains($message, 'certificate') => 'TLS: check CA file and certificate configuration',
            str_contains($message, 'timed out'), str_contains($message, 'refused') => 'Network: check DB_HOST, DB_PORT and firewall',
            default => 'Connection failed: inspect SQLSTATE and driver code',
        };
        // Do not print exception messages: they may contain credentials or SQL.
        $this->error('Database diagnostic failed: '.json_encode([
            'reason' => $reason,
            'sqlstate' => $info[0] ?? $error->getCode(),
            'driver_code' => $info[1] ?? null,
        ]));
        return 1;
    }
})->purpose('Check database connectivity without modifying data or exposing credentials');
