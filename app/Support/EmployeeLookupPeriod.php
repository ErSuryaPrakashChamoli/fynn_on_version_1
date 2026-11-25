<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The period the Employee Lookup page analyses: one month, a financial
 * year, all time, or any custom date range. It is chosen on the page
 * itself and travels in the URL; with nothing chosen it follows the topbar
 * period selector (SelectedMonth).
 *
 * A financial year still in progress and "all time" stop at the end of the
 * current month: a month that has not happened yet has no achievement, and
 * counting its target would only drag the percentage down. A custom range
 * is taken exactly as given.
 *
 * Targets and incentives are monthly by nature, so a longer period adds
 * them up month by month over months(); achievement and every list use the
 * exact start() – end() dates.
 */
final class EmployeeLookupPeriod
{
    public const MODE_MONTH = 'month';

    public const MODE_FINANCIAL_YEAR = 'fy';

    public const MODE_ALL = 'all';

    public const MODE_CUSTOM = 'custom';

    private function __construct(
        public readonly string $mode,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $label,
        public readonly ?Carbon $month = null,
        public readonly ?int $financialYear = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function modeOptions(): array
    {
        return [
            self::MODE_MONTH => 'Month',
            self::MODE_FINANCIAL_YEAR => 'Financial year',
            self::MODE_ALL => 'All time',
            self::MODE_CUSTOM => 'Custom range',
        ];
    }

    /**
     * The last three years of months, newest first: "2026-11" => "November 2026".
     *
     * @return array<string, string>
     */
    public static function monthOptions(): array
    {
        $options = [];
        $month = now()->startOfMonth();

        for ($i = 0; $i < 36; $i++) {
            $options[$month->format('Y-m')] = $month->format('F Y');
            $month = $month->copy()->subMonthNoOverflow();
        }

        return $options;
    }

    /**
     * The period chosen on the page; anything missing or malformed falls
     * back to what the topbar period selector says.
     */
    public static function fromInput(?string $mode, ?string $month = null, ?string $financialYear = null, ?string $from = null, ?string $to = null): self
    {
        $mode = array_key_exists((string) $mode, self::modeOptions()) ? $mode : null;

        return match ($mode) {
            self::MODE_MONTH => self::forMonth(self::parseMonth($month) ?? SelectedMonth::selectedMonth()),
            self::MODE_FINANCIAL_YEAR => self::forFinancialYear(is_numeric($financialYear) ? (int) $financialYear : SelectedMonth::financialYearStartFor(now())),
            self::MODE_ALL => self::allTime(),
            self::MODE_CUSTOM => ($range = self::parseRange($from, $to)) ? self::custom(...$range) : self::followTopbar(),
            default => self::followTopbar(),
        };
    }

    public static function followTopbar(): self
    {
        return match (SelectedMonth::mode()) {
            SelectedMonth::MODE_FINANCIAL_YEAR => self::forFinancialYear(SelectedMonth::financialYearStart()),
            SelectedMonth::MODE_ALL => self::allTime(),
            default => self::forMonth(SelectedMonth::selectedMonth()),
        };
    }

    public static function forMonth(Carbon $month): self
    {
        $start = $month->copy()->startOfMonth();

        return new self(self::MODE_MONTH, $start, $start->copy()->endOfMonth(), $start->format('F Y'), month: $start);
    }

    public static function forFinancialYear(int $startYear): self
    {
        [$start, $end] = SelectedMonth::financialYearRange($startYear);
        $start = Carbon::parse($start);
        $end = Carbon::parse($end);
        $label = SelectedMonth::financialYearLabel($startYear);

        if ($end->gt(now()->endOfMonth()) && $start->lte(now())) {
            $end = now()->endOfMonth();
            $label .= ' (to '.$end->format('M Y').')';
        }

        return new self(self::MODE_FINANCIAL_YEAR, $start, $end, $label, financialYear: $startYear);
    }

    /**
     * From the month of the oldest case on record to the end of this month.
     */
    public static function allTime(): self
    {
        $oldest = collect([
            Customer::query()->min('created_at'),
            Customer::query()->min('disbursal_date'),
        ])->filter()->map(fn (mixed $date): Carbon => Carbon::parse($date))->min();

        $start = ($oldest ?? now())->copy()->startOfMonth();
        $floor = Carbon::create(SelectedMonth::ALL_TIME_START_YEAR, 1, 1);

        if ($start->lt($floor)) {
            $start = $floor;
        }

        $end = now()->endOfMonth();

        return new self(self::MODE_ALL, $start, $end, 'All time ('.$start->format('M Y').' – '.$end->format('M Y').')');
    }

    public static function custom(Carbon $from, Carbon $to): self
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        return new self(self::MODE_CUSTOM, $start, $end, $start->format('d M Y').' – '.$end->format('d M Y'));
    }

    /**
     * The first day of every month the period touches.
     *
     * @return list<Carbon>
     */
    public function months(): array
    {
        $months = [];

        for ($cursor = $this->start->copy()->startOfMonth(); $cursor->lte($this->end); $cursor = $cursor->copy()->addMonthNoOverflow()->startOfMonth()) {
            $months[] = $cursor->copy();
        }

        return $months;
    }

    /**
     * Exactly one whole calendar month — the case where target, achievement
     * and incentive can be explained in full detail.
     */
    public function isSingleMonth(): bool
    {
        return $this->start->isSameDay($this->start->copy()->startOfMonth())
            && $this->end->isSameDay($this->start->copy()->endOfMonth());
    }

    /**
     * Whether a custom range cuts a month short at either end. Targets and
     * incentives still count those months whole.
     */
    public function hasPartialMonths(): bool
    {
        return ! $this->start->isSameDay($this->start->copy()->startOfMonth())
            || ! $this->end->isSameDay($this->end->copy()->endOfMonth());
    }

    /**
     * The URL parameters that reproduce this period.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return match ($this->mode) {
            self::MODE_MONTH => ['period' => $this->mode, 'month' => $this->start->format('Y-m')],
            self::MODE_FINANCIAL_YEAR => ['period' => $this->mode, 'fy' => (string) $this->financialYear],
            self::MODE_CUSTOM => ['period' => $this->mode, 'from' => $this->start->toDateString(), 'to' => $this->end->toDateString()],
            default => ['period' => $this->mode],
        };
    }

    /**
     * The days of the period the employee was on the rolls, or null when
     * they were not on the rolls on any of them. Mirrors the target engine
     * (OnRollsWindow, as TargetLedger reads it): joined on their
     * reporting date (else date of joining), gone after a recorded exit
     * date. An exit with no date recorded cannot be placed, so — as in the
     * engine — it does not end the window.
     *
     * @return array{start: Carbon, end: Carbon}|null
     */
    public function activeWindow(Employee $employee): ?array
    {
        $joined = self::parseDate($employee->reporting_date) ?? self::parseDate($employee->doj);
        $exited = self::isExited($employee) ? self::parseDate($employee->exit_date) : null;

        $start = $joined !== null && $joined->gt($this->start) ? $joined->copy()->startOfDay() : $this->start->copy();
        $end = $exited !== null && $exited->lt($this->end) ? $exited->copy()->endOfDay() : $this->end->copy();

        return $start->lte($end) ? ['start' => $start, 'end' => $end] : null;
    }

    public function wasActive(Employee $employee): bool
    {
        return $this->activeWindow($employee) !== null;
    }

    /**
     * Everyone on the rolls on at least one day of the period.
     *
     * @return Collection<int, int>
     */
    public function activeEmployeeIds(): Collection
    {
        return Employee::query()
            ->get(['id', 'reporting_date', 'doj', 'exit_status', 'exit_date'])
            ->filter(fn (Employee $employee): bool => $this->wasActive($employee))
            ->pluck('id')
            ->values();
    }

    public static function isExited(Employee $employee): bool
    {
        return strtolower(trim((string) $employee->exit_status)) === 'yes';
    }

    private static function parseMonth(?string $month): ?Carbon
    {
        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private static function parseRange(?string $from, ?string $to): ?array
    {
        $from = self::parseDate($from);
        $to = self::parseDate($to);

        if ($from === null || $to === null) {
            return null;
        }

        return $from->lte($to) ? [$from, $to] : [$to, $from];
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
