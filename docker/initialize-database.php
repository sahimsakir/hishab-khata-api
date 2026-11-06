<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Use a direct connection so advisory locks stay on the same PostgreSQL session.
$direct = getenv('DATABASE_URL_UNPOOLED');
if ($direct) {
    config(['database.connections.pgsql.url' => $direct]);
    Illuminate\Support\Facades\DB::purge('pgsql');
}
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'pgsql') {
    throw new RuntimeException('Production initialization requires PostgreSQL.');
}
$db->select('SELECT pg_advisory_lock(724681235)');
try {
    $status = Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    echo Illuminate\Support\Facades\Artisan::output();
    if ($status !== 0) {
        throw new RuntimeException('Migration failed.');
    }
    $clients = app(Laravel\Passport\ClientRepository::class);
    try {
        $clients->personalAccessClient('users');
    } catch (RuntimeException $e) {
        $clients->createPersonalAccessGrantClient('HishabKhata Browser', 'users');
    }
    echo "HishabKhata database initialization completed.\n";
} finally {
    $db->select('SELECT pg_advisory_unlock(724681235)');
}
