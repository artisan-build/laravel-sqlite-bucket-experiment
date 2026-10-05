<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ContentBuilder;
use Illuminate\Console\Command;
use Throwable;

/**
 * `content:build` — the one command in the Laravel Cloud build command.
 *
 * It must not be a deploy command: deploy commands run in a separate Kubernetes
 * pod and nothing they write reaches an instance.
 */
final class BuildContent extends Command
{
    protected $signature = 'content:build
                            {--content= : Directory of markdown files (default: content/)}
                            {--target= : SQLite file to write (default: database/content.sqlite)}';

    protected $description = 'Compile the markdown in content/ into a read-only SQLite file';

    public function handle(): int
    {
        $content = $this->option('content') ?: base_path('content');
        $target = $this->option('target') ?: database_path('content.sqlite');

        $before = is_file($target) ? filesize($target) : 0;

        try {
            $result = (new ContentBuilder($content, $target))->build();
        } catch (Throwable $e) {
            // Loud, and non-zero: a build that cannot compile the content must
            // fail the deployment rather than ship the previous database or none.
            $this->components->error('content:build failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'content:build wrote %d posts to %s (%s bytes, was %s, fts5 %s) in %ss',
            $result['posts'],
            $result['target'],
            number_format($result['bytes']),
            number_format($before),
            $result['fts'] ? 'on' : 'OFF',
            $result['seconds'],
        ));

        // A single machine-readable line, so the Cloud build log carries the
        // evidence without anyone having to shell into anything.
        $this->line('CONTENT-BUILD '.json_encode($result + ['previous_bytes' => $before]));

        return self::SUCCESS;
    }
}
