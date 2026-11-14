<?php

namespace App\Support;

use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer Data S. Nos. (the "#" column) nobody holds yet, grouped into
 * runs the Admin can type straight into "Assign by S. No.".
 *
 * A run stops at a record that is already assigned or converted, and at a
 * record of another upload template, so each run is one From/To pair that
 * hands out records of a single template. Ids left behind by deleted
 * records do not break a run: there is nothing there to assign.
 */
class AiRecordSerialRanges
{
    /**
     * Every unassigned run across all months, lowest S. No. first.
     *
     * @return Collection<int, array{from: int, to: int, count: int, configuration: string, uploaded_on: ?string}>
     */
    public static function unassigned(): Collection
    {
        // Same rule as AiCustomerRecord::scopeUnassigned(), as a 1/0 column.
        $flagged = DB::table('ai_customer_records')
            ->select(['id', 'schema_id', 'created_at'])
            ->selectRaw('CASE WHEN customer_id IS NULL AND NOT EXISTS ('
                .'SELECT 1 FROM customer_assignments WHERE customer_assignments.ai_customer_record_id = ai_customer_records.id'
                .') THEN 1 ELSE 0 END AS is_open');

        // Gaps-and-islands: the two row numbers move together while
        // consecutive records share a template and an open/taken state, so
        // their difference names one run.
        $ranked = DB::query()
            ->fromSub($flagged, 'flagged')
            ->select(['id', 'schema_id', 'created_at', 'is_open'])
            ->selectRaw('ROW_NUMBER() OVER (ORDER BY id) - ROW_NUMBER() OVER (PARTITION BY schema_id, is_open ORDER BY id) AS run_key');

        $runs = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('is_open', 1)
            ->groupBy('schema_id', 'run_key')
            ->selectRaw('schema_id, MIN(id) AS from_id, MAX(id) AS to_id, COUNT(*) AS total, MIN(created_at) AS first_created_at')
            ->orderBy('from_id')
            ->get();

        $configurations = AiDocumentSchema::query()
            ->whereIn('id', $runs->pluck('schema_id')->filter()->unique())
            ->pluck('name', 'id');

        return $runs->map(fn (object $run): array => [
            'from' => (int) $run->from_id,
            'to' => (int) $run->to_id,
            'count' => (int) $run->total,
            'configuration' => (string) ($configurations[$run->schema_id] ?? '—'),
            'uploaded_on' => filled($run->first_created_at) ? Carbon::parse($run->first_created_at)->format('d M Y') : null,
        ])->values();
    }

    /**
     * The runs with the S. No. ranges in $taken cut out — what is still free
     * once the rows above have claimed their ranges. A cut run is narrowed to
     * the records actually left in it.
     *
     * @param  Collection<int, array{from: int, to: int, count: int, configuration: string, uploaded_on: ?string}>  $runs
     * @param  array<array-key, array{from: int, to: int}>  $taken
     * @return Collection<int, array{from: int, to: int, count: int, configuration: string, uploaded_on: ?string}>
     */
    public static function without(Collection $runs, array $taken): Collection
    {
        if ($taken === []) {
            return $runs;
        }

        return $runs->flatMap(function (array $run) use ($taken): array {
            $pieces = [[$run['from'], $run['to']]];

            foreach ($taken as $range) {
                $remaining = [];

                foreach ($pieces as [$from, $to]) {
                    if ($range['to'] < $from || $range['from'] > $to) {
                        $remaining[] = [$from, $to];

                        continue;
                    }

                    if ($range['from'] > $from) {
                        $remaining[] = [$from, $range['from'] - 1];
                    }

                    if ($range['to'] < $to) {
                        $remaining[] = [$range['to'] + 1, $to];
                    }
                }

                $pieces = $remaining;
            }

            if ($pieces === [[$run['from'], $run['to']]]) {
                return [$run];
            }

            return collect($pieces)
                ->map(fn (array $piece): ?array => self::narrow($run, $piece[0], $piece[1]))
                ->filter()
                ->values()
                ->all();
        })->values();
    }

    /**
     * "12" for a single record, "12 – 40" for a run.
     *
     * @param  array{from: int, to: int}  $range
     */
    public static function label(array $range): string
    {
        return $range['from'] === $range['to'] ? (string) $range['from'] : "{$range['from']} – {$range['to']}";
    }

    /**
     * A run cut down to the records that exist between $from and $to. Every
     * record inside a run is unassigned and of the run's template, so a
     * plain count is the number left to give out.
     *
     * @param  array{from: int, to: int, count: int, configuration: string, uploaded_on: ?string}  $run
     * @return array{from: int, to: int, count: int, configuration: string, uploaded_on: ?string}|null
     */
    private static function narrow(array $run, int $from, int $to): ?array
    {
        $left = AiCustomerRecord::query()
            ->toBase()
            ->whereBetween('id', [$from, $to])
            ->selectRaw('MIN(id) AS first_id, MAX(id) AS last_id, COUNT(*) AS total')
            ->first();

        if ($left === null || (int) $left->total === 0) {
            return null;
        }

        return [...$run, 'from' => (int) $left->first_id, 'to' => (int) $left->last_id, 'count' => (int) $left->total];
    }
}
