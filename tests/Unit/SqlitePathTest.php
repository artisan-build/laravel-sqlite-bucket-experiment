<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SqlitePath;
use PHPUnit\Framework\TestCase;

final class SqlitePathTest extends TestCase
{
    protected function tearDown(): void
    {
        SqlitePath::flush();
        putenv('LITESTREAM_DB_PATH');
        putenv('LARAVEL_CLOUD');

        parent::tearDown();
    }

    public function test_it_keeps_the_database_in_the_repository_when_not_on_cloud(): void
    {
        SqlitePath::flush();

        $this->assertSame(
            dirname(__DIR__, 2).'/database/database.sqlite',
            SqlitePath::resolve()
        );
    }

    public function test_it_moves_the_database_off_the_deployed_tree_on_cloud(): void
    {
        putenv('LARAVEL_CLOUD=1');
        SqlitePath::flush();

        $this->assertTrue(SqlitePath::onCloud());
        $this->assertSame(SqlitePath::CLOUD_DIRECTORY.'/database.sqlite', SqlitePath::resolve());
        $this->assertSame(SqlitePath::CLOUD_DIRECTORY, SqlitePath::directory());
    }

    public function test_an_explicit_path_wins(): void
    {
        putenv('LITESTREAM_DB_PATH=/tmp/somewhere-else/db.sqlite');
        SqlitePath::flush();

        $this->assertSame('/tmp/somewhere-else/db.sqlite', SqlitePath::resolve());
    }
}
