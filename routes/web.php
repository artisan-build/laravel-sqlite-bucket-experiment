<?php

declare(strict_types=1);

use App\Jobs\RecordProbe;
use App\Models\Probe as ProbeRow;
use App\Support\Litestream;
use App\Support\Probe;
use App\Support\SqlitePath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => 'laravel-sqlite-bucket-experiment',
    'probes' => ['/probe/info', '/probe/write?seq=N', '/probe/rows', '/probe/diag', '/probe/sync'],
]));

/**
 * Everything about the machine and the database file serving this request.
 *
 * Comparing this with the same block printed by a build hook, a deploy command
 * or `cloud command:run` is how the experiment locates where each phase runs.
 */
Route::get('/probe/info', function () {
    try {
        $rows = ProbeRow::summary();
    } catch (Throwable $e) {
        $rows = ['error' => $e->getMessage()];
    }

    return response()->json(Probe::snapshot('http') + ['journal_mode' => Probe::journalMode(), 'rows' => $rows]);
});

/** Append one row. The write path under test. */
Route::get('/probe/write', function (Request $request) {
    $seq = (int) $request->query('seq', '0');

    if ($seq <= 0) {
        return response()->json(['error' => 'seq must be a positive integer'], 422);
    }

    $created = true;

    try {
        ProbeRow::create([
            'seq' => $seq,
            'label' => (string) $request->query('label', 'write'),
            'host' => gethostname(),
            'machine_id' => Probe::machineId(),
            'written_at' => now(),
        ]);
    } catch (Throwable $e) {
        // A duplicate seq means the writer retried; it is not a new row and it
        // must not be reported as one.
        if (! str_contains($e->getMessage(), 'UNIQUE')) {
            throw $e;
        }

        $created = false;
    }

    return response()->json([
        'seq' => $seq,
        'created' => $created,
        'machine_id' => Probe::machineId(),
        'host' => gethostname(),
        'daemon_pid' => Litestream::daemonPid(),
        'rows' => ProbeRow::count(),
    ]);
});

/** Push a job onto the queue. Whether it ever runs, and where, is the finding. */
Route::get('/probe/queue', function (Request $request) {
    $seq = (int) $request->query('seq', '0');

    if ($seq <= 0) {
        return response()->json(['error' => 'seq must be a positive integer'], 422);
    }

    RecordProbe::dispatch($seq);

    return response()->json([
        'dispatched' => $seq,
        'connection' => config('queue.default'),
        'host' => gethostname(),
        'pending' => DB::table('jobs')->count(),
    ]);
});

/** What is actually in the database right now, and where the holes are. */
Route::get('/probe/rows', fn () => response()->json(ProbeRow::summary(detailed: true)));

/** Force Litestream to flush, so a test can take a known-good checkpoint. */
Route::get('/probe/sync', fn () => response()->json([
    'sync' => Litestream::sync(),
    'status' => Litestream::status(),
]));

/** A fixed set of shell diagnostics. No arbitrary command execution. */
Route::get('/probe/diag', fn () => response()->json([
    'machine_id' => Probe::machineId(),
    'ps' => Litestream::run(['ps', '-eo', 'pid,ppid,etime,comm'])['output'],
    'db_dir' => Litestream::run(['ls', '-la', SqlitePath::directory()])['output'],
    'app_dir' => Litestream::run(['ls', '-la', base_path()])['output'],
    'bin_dir' => Litestream::run(['ls', '-la', base_path('bin')])['output'],
    'df' => Litestream::run(['df', '-h'])['output'],
    'litestream_status' => Litestream::status(),
    'litestream_ltx' => Litestream::ltx(),
    'litestream_log' => Litestream::tailLog(60),
]));
