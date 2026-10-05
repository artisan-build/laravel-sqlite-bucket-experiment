<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Probe as ProbeRow;
use App\Support\Probe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes one row, from wherever the queue happens to run it.
 *
 * The requirement under test is "everything that touches the database runs on
 * the one instance". A job that lands on separate compute would see an empty
 * database — so the row this job writes, and the hostname it records, is the
 * answer to whether a queue is usable at all in this design.
 */
final class RecordProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $seq) {}

    public function handle(): void
    {
        ProbeRow::query()->updateOrCreate(['seq' => $this->seq], [
            'label' => 'queue',
            'host' => gethostname(),
            'machine_id' => Probe::machineId(),
            'written_at' => now(),
        ]);
    }
}
