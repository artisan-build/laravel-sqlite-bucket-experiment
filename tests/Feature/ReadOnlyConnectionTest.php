<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Content\Post;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesCompiledContent;
use Tests\TestCase;

/**
 * The whole design rests on one property: the connection the application serves
 * from cannot be written to. Not "nothing writes to it" — cannot.
 */
final class ReadOnlyConnectionTest extends TestCase
{
    use UsesCompiledContent;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = $this->useCompiledContent();
    }

    public function test_the_content_connection_is_the_default_one(): void
    {
        $this->assertSame('content', config('database.default'));

        // The application declares exactly one connection. Laravel merges the
        // framework's own base config back in, so config('database.connections')
        // still lists sqlite/mysql/pgsql/sqlsrv -- inert, since nothing resolves
        // them and no DB_* variable is set anywhere.
        $fresh = require base_path('config/database.php');

        $this->assertSame(['content'], array_keys($fresh['connections']));
        $this->assertSame([], $fresh['redis']);
    }

    public function test_the_configured_database_is_a_read_only_immutable_uri(): void
    {
        // Asserting the shipped configuration, not the test override.
        $fresh = require base_path('config/database.php');

        $this->assertStringStartsWith('file:', $fresh['connections']['content']['database']);
        $this->assertStringEndsWith('content.sqlite?mode=ro&immutable=1', $fresh['connections']['content']['database']);
    }

    public function test_an_insert_through_the_connection_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('attempt to write a readonly database');

        DB::connection('content')->insert(
            "insert into posts (slug, title, date, summary, html, source, source_bytes)
             values ('x', 'x', '2026-01-01', '', '', 'x', 0)"
        );
    }

    public function test_an_eloquent_save_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('attempt to write a readonly database');

        tap(new Post)->forceFill([
            'slug' => 'x', 'title' => 'x', 'date' => '2026-01-01',
            'summary' => '', 'html' => '', 'source' => 'x', 'source_bytes' => 0,
        ])->save();
    }

    public function test_an_update_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('attempt to write a readonly database');

        DB::connection('content')->update("update posts set title = 'x'");
    }

    public function test_a_delete_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('attempt to write a readonly database');

        DB::connection('content')->delete('delete from posts');
    }

    public function test_ddl_throws(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('attempt to write a readonly database');

        DB::connection('content')->statement('create table probe (x integer)');
    }

    public function test_a_refused_write_changes_nothing(): void
    {
        $before = Post::query()->count();

        try {
            DB::connection('content')->delete('delete from posts');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame($before, Post::query()->count());
        $this->assertSame($before, (int) DB::connection('content')->table('meta')->where('key', 'posts')->value('value'));
    }

    public function test_reading_creates_no_wal_or_shm_sidecar(): void
    {
        Post::published()->get();

        $this->assertFileDoesNotExist($this->path.'-wal');
        $this->assertFileDoesNotExist($this->path.'-shm');
    }

    public function test_opening_a_missing_content_file_fails_loudly(): void
    {
        $this->useCompiledContent(sys_get_temp_dir().'/content-absent-'.bin2hex(random_bytes(4)).'.sqlite');

        // A build that did not happen must not serve an empty site.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('unable to open database file');

        Post::query()->count();
    }

    public function test_no_driver_depends_on_a_writable_database(): void
    {
        $this->assertSame('cookie', config('session.driver'));
        $this->assertSame('file', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
    }
}
