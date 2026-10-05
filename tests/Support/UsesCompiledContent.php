<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Content\ContentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Points the `content` connection at a freshly compiled copy of the real
 * content/ directory, opened exactly the way production opens it — same URI,
 * same read-only flags. A test that pointed a writable connection at a fixture
 * would prove nothing about the thing that ships.
 */
trait UsesCompiledContent
{
    private static ?string $compiled = null;

    protected function compiledContent(): string
    {
        if (self::$compiled !== null && is_file(self::$compiled)) {
            return self::$compiled;
        }

        $target = sys_get_temp_dir().'/content-test-'.getmypid().'.sqlite';

        (new ContentBuilder(base_path('content'), $target))->build();

        return self::$compiled = $target;
    }

    protected function useCompiledContent(?string $path = null): string
    {
        $path ??= $this->compiledContent();

        config(['database.connections.content.database' => 'file:'.$path.'?mode=ro&immutable=1']);

        DB::purge('content');

        return $path;
    }
}
