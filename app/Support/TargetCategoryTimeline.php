<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Services\ReportingLineService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The target category (employees.category) each employee held on each day,
 * rebuilt from their 'target_category' history rows
 * (ReportingLineService::recordCategoryChange()).
 *
 * Before an employee's first change they hold its old category, from each
 * change's effective date its new one, and from the latest change onward
 * whatever employees.category holds today — so a category written without a
 * history row (an importer, or a correction on an exited employee) still
 * applies from the last recorded change. With no rows at all today's
 * category applies to every day, as it always did.
 *
 * Every 'target_category' row is read once per request (there are few) and
 * forgotten whenever one is written.
 */
final class TargetCategoryTimeline
{
    /**
     * The category $employee held on $day.
     */
    public static function categoryOn(Employee $employee, CarbonInterface $day): ?string
    {
        $date = $day->toDateString();
        $category = null;

        foreach (self::lines($employee) as $line) {
            if ($line['from'] !== null && $line['from'] > $date) {
                break;
            }

            $category = $line['category'];
        }

        return $category;
    }

    /**
     * The categories $employee held across [$start, $end] in order, with
     * the days each was held. A single span means no change in the period.
     *
     * @return list<array{category: ?string, start: CarbonImmutable, end: CarbonImmutable, days: int}>
     */
    public static function spans(Employee $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        $periodStart = CarbonImmutable::parse($start->toDateString());
        $periodEnd = CarbonImmutable::parse($end->toDateString());
        $lines = self::lines($employee);
        $spans = [];

        foreach ($lines as $index => $line) {
            $lineStart = $line['from'] === null ? $periodStart : CarbonImmutable::parse($line['from']);
            $lineEnd = isset($lines[$index + 1]) ? CarbonImmutable::parse($lines[$index + 1]['from'])->subDay() : $periodEnd;

            $spanStart = $lineStart->max($periodStart);
            $spanEnd = $lineEnd->min($periodEnd);

            if ($spanStart->gt($spanEnd)) {
                continue;
            }

            $last = array_key_last($spans);

            if ($last !== null && $spans[$last]['category'] === $line['category']) {
                $spans[$last]['end'] = $spanEnd;
                $spans[$last]['days'] = (int) $spans[$last]['start']->diffInDays($spanEnd) + 1;

                continue;
            }

            $spans[] = [
                'category' => $line['category'],
                'start' => $spanStart,
                'end' => $spanEnd,
                'days' => (int) $spanStart->diffInDays($spanEnd) + 1,
            ];
        }

        return $spans;
    }

    /**
     * The day of $employee's latest recorded category change, if any — a
     * new change may not be dated before it.
     */
    public static function lastChangeDate(Employee $employee): ?CarbonImmutable
    {
        $rows = self::rows()[$employee->id] ?? [];

        return $rows === [] ? null : CarbonImmutable::parse($rows[array_key_last($rows)]['date']);
    }

    /**
     * Drop the per-request copy of the history rows after one is written.
     */
    public static function forget(): void
    {
        app()->forgetInstance(self::cacheKey());
    }

    /**
     * $employee's categories, oldest first: the first row's old category
     * from the start of time, then each row's new one from its effective
     * date, the latest being today's employees.category.
     *
     * @return list<array{from: ?string, category: ?string}>
     */
    private static function lines(Employee $employee): array
    {
        $current = filled($employee->category) ? (string) $employee->category : null;
        $rows = self::rows()[$employee->id] ?? [];

        if ($rows === []) {
            return [['from' => null, 'category' => $current]];
        }

        $lines = [['from' => null, 'category' => $rows[0]['old']]];

        foreach ($rows as $row) {
            $lines[] = ['from' => $row['date'], 'category' => $row['new']];
        }

        $lines[array_key_last($lines)]['category'] = $current;

        return $lines;
    }

    /**
     * Every 'target_category' row, by employee, oldest first.
     *
     * @return array<int, list<array{date: string, old: ?string, new: ?string}>>
     */
    private static function rows(): array
    {
        $key = self::cacheKey();

        if (! app()->bound($key)) {
            $rows = [];

            EmployeeReportingHistory::query()
                ->toBase()
                ->where('change_type', ReportingLineService::CHANGE_TARGET_CATEGORY)
                ->orderBy('employee_id')
                ->orderBy('effective_date')
                ->orderBy('id')
                ->get(['employee_id', 'effective_date', 'old_category', 'new_category'])
                ->each(function (object $row) use (&$rows): void {
                    $rows[(int) $row->employee_id][] = [
                        'date' => substr((string) $row->effective_date, 0, 10),
                        'old' => filled($row->old_category) ? (string) $row->old_category : null,
                        'new' => filled($row->new_category) ? (string) $row->new_category : null,
                    ];
                });

            app()->instance($key, $rows);
        }

        return app($key);
    }

    private static function cacheKey(): string
    {
        return self::class.'.rows.'.DB::getDefaultConnection();
    }
}
