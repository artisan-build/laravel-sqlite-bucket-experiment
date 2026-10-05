<?php

declare(strict_types=1);

use App\Content\Post;
use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/', [PostController::class, 'index'])->name('posts.index');
Route::get('/search', [PostController::class, 'search'])->name('posts.search');
Route::get('/posts/{slug}', [PostController::class, 'show'])->name('posts.show');

/*
|--------------------------------------------------------------------------
| Probes
|--------------------------------------------------------------------------
|
| Not part of the pattern being proposed — these exist so this experiment's
| claims can be checked from outside, over https, on the real platform. They
| publish a fixed set of non-secret facts and never an environment value.
|
*/

Route::prefix('probe')->group(function (): void {
    /** Which build of which content is this container serving? */
    Route::get('version', function () {
        $path = database_path('content.sqlite');

        return response()->json([
            'commit' => substr((string) getenv('LARAVEL_CLOUD_COMMIT_SHA'), 0, 12) ?: null,
            'build' => getenv('LARAVEL_CLOUD_BUILD_NUMBER') ?: null,
            'deploy' => getenv('LARAVEL_CLOUD_DEPLOY') ?: null,
            'host' => gethostname(),
            'posts' => Post::query()->count(),
            'latest' => Post::published()->value('slug'),
            'bytes' => is_file($path) ? filesize($path) : 0,
            'sha256' => is_file($path) ? substr(hash_file('sha256', $path), 0, 16) : null,
            'at' => now()->toIso8601String(),
        ]);
    });

    /** The database file, the connection, and what SQLite says about both. */
    Route::get('info', function () {
        $path = database_path('content.sqlite');
        $connection = DB::connection('content');

        return response()->json([
            'host' => gethostname(),
            'php' => PHP_VERSION,
            'sqlite' => $connection->scalar('select sqlite_version()'),
            'database_config' => config('database.connections.content.database'),
            'default_connection' => config('database.default'),
            'file' => [
                'path' => $path,
                'exists' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : 0,
                'writable' => is_file($path) && is_writable($path),
                'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
                'wal_sidecar' => is_file($path.'-wal'),
                'shm_sidecar' => is_file($path.'-shm'),
            ],
            'pragmas' => [
                'journal_mode' => $connection->scalar('pragma journal_mode'),
                'query_only' => $connection->scalar('pragma query_only'),
            ],
            'meta' => $connection->table('meta')->pluck('value', 'key'),
            'tables' => $connection->table('sqlite_master')
                ->whereIn('type', ['table', 'index'])->orderBy('name')->pluck('name'),
            'posts' => Post::query()->count(),
            'drivers' => [
                'session' => config('session.driver'),
                'cache' => config('cache.default'),
                'queue' => config('queue.default'),
            ],
            'migrations_table_exists' => Schema::connection('content')->hasTable('migrations'),
        ]);
    });

    /**
     * Try to write, three ways, and report what happened.
     *
     * The design claims the connection refuses writes. A claim like that is
     * worth nothing unless it is checked on the machine that serves traffic,
     * so this probe tries raw SQL, an Eloquent insert and a DDL statement.
     */
    Route::get('write', function () {
        $attempts = [
            'insert' => fn () => DB::connection('content')->insert(
                "insert into posts (slug, title, date, summary, html, source, source_bytes) values ('probe', 'probe', '2026-01-01', '', '', 'probe', 0)"
            ),
            // forceFill, not create(): mass-assignment protection would refuse
            // this before the connection ever saw it, which would prove nothing.
            'eloquent' => fn () => tap(new Post)->forceFill([
                'slug' => 'probe-eloquent', 'title' => 'probe', 'date' => '2026-01-01',
                'summary' => '', 'html' => '', 'source' => 'probe', 'source_bytes' => 0,
            ])->save(),
            'ddl' => fn () => DB::connection('content')->statement('create table probe_write (x integer)'),
            'delete' => fn () => DB::connection('content')->delete('delete from posts where id = 1'),
        ];

        $results = [];

        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                $results[$name] = ['refused' => false, 'note' => 'THE WRITE SUCCEEDED'];
            } catch (Throwable $e) {
                $results[$name] = [
                    'refused' => true,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $refused = collect($results)->every(fn (array $r) => $r['refused']);

        return response()->json([
            'host' => gethostname(),
            'all_writes_refused' => $refused,
            'rows_after' => Post::query()->count(),
            'attempts' => $results,
        ], $refused ? 200 : 500);
    });
});
