<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RecordProbe;
use App\Models\Probe as ProbeRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class QueueProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_endpoint_pushes_the_job(): void
    {
        Queue::fake();

        $this->getJson('/probe/queue?seq=42')->assertOk()->assertJson(['dispatched' => 42]);

        Queue::assertPushed(RecordProbe::class);
    }

    public function test_the_job_records_the_host_that_ran_it(): void
    {
        (new RecordProbe(42))->handle();

        $row = ProbeRow::sole();

        $this->assertSame(42, $row->seq);
        $this->assertSame('queue', $row->label);
        $this->assertSame(gethostname(), $row->host);
    }

    public function test_a_rerun_job_does_not_duplicate_its_row(): void
    {
        (new RecordProbe(42))->handle();
        (new RecordProbe(42))->handle();

        $this->assertSame(1, ProbeRow::count());
    }
}
