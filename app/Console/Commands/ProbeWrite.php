<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Probe as ProbeRow;
use App\Support\Probe;
use Illuminate\Console\Command;

/** Write one row from a non-web context (the scheduler, a queued job, a deploy command). */
final class ProbeWrite extends Command
{
    protected $signature = 'probe:write {--seq=} {--label=cli}';

    protected $description = 'Insert one probe row and report the table state';

    public function handle(): int
    {
        $seq = $this->option('seq') !== null
            ? (int) $this->option('seq')
            : (int) (ProbeRow::max('seq') ?? 0) + 1;

        ProbeRow::query()->updateOrCreate(['seq' => $seq], [
            'label' => (string) $this->option('label'),
            'host' => gethostname(),
            'machine_id' => Probe::machineId(),
            'written_at' => now(),
        ]);

        $this->line('PROBE-WRITE '.json_encode([
            'seq' => $seq,
            'label' => $this->option('label'),
            'machine_id' => Probe::machineId(),
            'summary' => ProbeRow::summary(),
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
