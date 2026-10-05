<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Litestream;
use App\Support\Probe;
use Illuminate\Console\Command;

/**
 * A long-lived process that only ever reports where it is.
 *
 * This is the question-(a) probe: if a Cloud "background process" shares the web
 * container, its machine id matches the one the HTTP probe reports and it is a
 * viable place to run Litestream. It deliberately never opens the database —
 * a background process in a container of its own would create an empty file and
 * could replicate it over a live replica, which is the failure this experiment
 * exists to avoid.
 */
final class ProbeSidecar extends Command
{
    protected $signature = 'probe:sidecar {--interval=15}';

    protected $description = 'Report this process location on a loop, without touching the database';

    public function handle(): int
    {
        $interval = max(5, (int) $this->option('interval'));

        while (true) {
            $this->line('PROBE-SIDECAR '.json_encode([
                'at' => gmdate('c'),
                'host' => gethostname(),
                'machine_id' => Probe::machineId(),
                'pid' => getmypid(),
                'db' => Probe::snapshot('sidecar')['db'],
                'litestream_ready' => Litestream::ready(),
                'litestream_pid' => Litestream::daemonPid(),
            ], JSON_UNESCAPED_SLASHES));

            sleep($interval);
        }
    }
}
