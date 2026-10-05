<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Probe as ProbeRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProbeEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_write_appends_a_row(): void
    {
        $this->getJson('/probe/write?seq=1&label=probe')
            ->assertOk()
            ->assertJson(['seq' => 1, 'created' => true, 'rows' => 1]);

        $this->assertSame('probe', ProbeRow::sole()->label);
    }

    public function test_a_replayed_sequence_number_is_not_counted_as_a_new_row(): void
    {
        $this->getJson('/probe/write?seq=7')->assertOk();

        $this->getJson('/probe/write?seq=7')
            ->assertOk()
            ->assertJson(['seq' => 7, 'created' => false, 'rows' => 1]);
    }

    public function test_a_write_without_a_sequence_number_is_rejected(): void
    {
        $this->getJson('/probe/write')->assertStatus(422);

        $this->assertSame(0, ProbeRow::count());
    }

    public function test_the_row_summary_names_every_missing_sequence_number(): void
    {
        foreach ([1, 2, 5, 6, 10] as $seq) {
            $this->getJson('/probe/write?seq='.$seq)->assertOk();
        }

        $this->getJson('/probe/rows')
            ->assertOk()
            ->assertJson([
                'count' => 5,
                'min' => 1,
                'max' => 10,
                'gap_count' => 2,
                'gaps' => [[3, 4], [7, 9]],
                'seqs' => [1, 2, 5, 6, 10],
            ]);
    }

    public function test_a_contiguous_run_reports_no_gaps(): void
    {
        foreach (range(1, 5) as $seq) {
            $this->getJson('/probe/write?seq='.$seq)->assertOk();
        }

        $this->getJson('/probe/rows')->assertOk()->assertJson(['count' => 5, 'gap_count' => 0, 'gaps' => []]);
    }

    public function test_the_info_probe_reports_the_database_file_and_journal_mode(): void
    {
        $response = $this->getJson('/probe/info')->assertOk();

        $this->assertSame('http', $response->json('context'));
        $this->assertNotNull($response->json('journal_mode'));
        $this->assertNotNull($response->json('db.path'));
        $this->assertFalse($response->json('litestream.enabled'));
    }

    public function test_the_info_probe_never_prints_an_injected_credential(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $body = $this->get('/probe/info')->assertOk()->getContent();

        $this->assertStringNotContainsString(config('app.key'), (string) $body);
        $this->assertStringContainsString('env_keys', (string) $body);
    }
}
