<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Litestream;
use App\Support\Probe;
use App\Support\SqlitePath;
use Illuminate\Console\Command;

/**
 * Print the same machine/database snapshot the HTTP probe prints.
 *
 * Run from a build command, a deploy command and `cloud command:run`, its output
 * lands in the deployment log — which is how the experiment proves whether those
 * phases share a container and a filesystem with the web process.
 */
final class ProbeWhere extends Command
{
    protected $signature = 'probe:where {tag=cli}';

    protected $description = 'Print where this process is running and which SQLite file it can see';

    public function handle(): int
    {
        $snapshot = Probe::snapshot('cli:'.$this->argument('tag'));

        $snapshot['shell'] = [
            'ps' => Litestream::run(['ps', '-eo', 'pid,ppid,etime,comm'])['output'],
            'db_dir' => Litestream::run(['ls', '-la', SqlitePath::directory()])['output'],
            'tmp' => Litestream::run(['ls', '-la', '/tmp'])['output'],
            'bin' => Litestream::run(['ls', '-la', base_path('bin')])['output'],
        ];

        $this->line('PROBE-WHERE-BEGIN '.$this->argument('tag'));
        $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('PROBE-WHERE-END '.$this->argument('tag'));

        return self::SUCCESS;
    }
}
