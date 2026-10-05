<?php

use App\Support\Litestream;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Restore the SQLite database from the attached bucket and start replicating it
// BEFORE the framework can open a connection. Laravel Cloud gives a PHP app no
// start command to wrap, so the application does it itself: the first request
// into a fresh container pays for the restore under an exclusive lock, and every
// later request short-circuits on a readiness marker. A failed restore must stop
// the boot — serving from an empty database would replicate that emptiness over
// the real one.
try {
    Litestream::boot();
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'database not ready',
        'detail' => $e->getMessage(),
        'litestream' => Litestream::report(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit(1);
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
