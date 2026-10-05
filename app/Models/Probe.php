<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Probe extends Model
{
    public $timestamps = false;

    protected $fillable = ['seq', 'label', 'host', 'machine_id', 'written_at'];

    protected $casts = ['seq' => 'integer', 'written_at' => 'datetime'];

    /**
     * What is in the table, and — the number the overlap test turns on — which
     * sequence numbers are missing from an otherwise contiguous run.
     *
     * @return array<string, mixed>
     */
    public static function summary(bool $detailed = false): array
    {
        $seqs = self::query()->orderBy('seq')->pluck('seq')->all();

        $gaps = [];

        for ($i = 1; $i < count($seqs); $i++) {
            if ($seqs[$i] !== $seqs[$i - 1] + 1) {
                $gaps[] = [$seqs[$i - 1] + 1, $seqs[$i] - 1];
            }
        }

        return array_filter([
            'count' => count($seqs),
            'min' => $seqs[0] ?? null,
            'max' => $seqs[count($seqs) - 1] ?? null,
            'gap_count' => count($gaps),
            'gaps' => $gaps,
            'seqs' => $detailed ? $seqs : null,
            'labels' => $detailed ? self::query()->selectRaw('label, count(*) as n')->groupBy('label')->pluck('n', 'label') : null,
            // Grouped by HOST, not machine_id: /proc/sys/kernel/random/boot_id
            // is the Kubernetes node's, so every container on a node shares it.
            // The hostname is the only per-container identity Cloud gives us.
            'hosts' => $detailed ? self::query()->selectRaw('host, count(*) as n, min(seq) as first, max(seq) as last, min(written_at) as from_at, max(written_at) as to_at')->groupBy('host')->get() : null,
        ], fn ($v) => $v !== null);
    }
}
