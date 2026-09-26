<?php

namespace App\Services;

use App\Enums\FollowUpOutcome;
use App\Models\FollowUp;
use App\Models\User;
use App\Support\HierarchyHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Judges how well follow-ups are being kept, for the calendars and the
 * supervisors' Follow-up Monitor.
 *
 * Nothing extra is stored: every follow-up interaction already inserts a new
 * follow_ups row, so whether a due follow-up was kept is read straight off
 * the prospect's log. A row that fell due is "acted on" when the next row for
 * the same prospect was logged, and on time if that happened no later than
 * the end of the due day plus GRACE_HOURS.
 *
 * A row whose successor was logged before its due day even began never fell
 * due at all (the date was moved first), so it is left out of every count.
 */
class FollowUpMonitorService
{
    /** Hours after the end of the due day that still count as on time. */
    public const GRACE_HOURS = 24;

    /**
     * Open follow-ups one caller can realistically work in a day. An 8-hour
     * shift at ~10 minutes a call with notes gives ~48 slots; keeping about
     * half the day free for fresh leads leaves 25.
     */
    public const DAILY_CAPACITY = 25;

    /** Gap between follow-ups when a backlog is spread over coming days. */
    public const SLOT_MINUTES = 15;

    public const MODULE_ALL = 'all';

    public const MODULE_CUSTOMER = 'customer';

    public const MODULE_LEAD = 'lead';

    /**
     * @return array<string, string>
     */
    public static function moduleOptions(): array
    {
        return [
            self::MODULE_ALL => 'All follow-ups',
            self::MODULE_CUSTOMER => 'Customers & assigned leads',
            self::MODULE_LEAD => 'Raw leads',
        ];
    }

    /** The last moment a follow-up due at $due can be acted on and still be on time. */
    public function deadlineFor(Carbon $due): Carbon
    {
        return $due->copy()->endOfDay()->addHours(self::GRACE_HOURS);
    }

    /**
     * Every follow-up in $query that fell (or falls) due between $from and
     * $to, each with its verdict set as `monitor_outcome` (a FollowUpOutcome)
     * and the moment it was acted on as `acted_at` (null when it never was).
     *
     * @param  Builder<FollowUp>  $query
     * @return Collection<int, FollowUp>
     */
    public function classify(Builder $query, Carbon $from, Carbon $to, ?Carbon $now = null): Collection
    {
        $now ??= now();

        $rows = $query->clone()
            ->scheduled()
            ->whereBetween($query->qualifyColumn('next_follow_up_date'), [$from, $to])
            ->get();

        if ($rows->isEmpty()) {
            return $rows->toBase();
        }

        $successors = $this->laterRowsBySubject($rows);

        return $rows
            ->toBase()
            ->map(function (FollowUp $followUp) use ($successors, $now): ?FollowUp {
                $due = $followUp->next_follow_up_date;
                $next = ($successors[$followUp->subject_key] ?? collect())
                    ->first(fn (FollowUp $later): bool => $later->id > $followUp->id);

                if ($next && $next->created_at->lt($due->copy()->startOfDay())) {
                    return null;
                }

                $deadline = $this->deadlineFor($due);

                $outcome = match (true) {
                    $next !== null => $next->created_at->lte($deadline) ? FollowUpOutcome::OnTime : FollowUpOutcome::Late,
                    $now->gt($deadline) => FollowUpOutcome::Missed,
                    $now->gte($due) => FollowUpOutcome::Pending,
                    default => FollowUpOutcome::Upcoming,
                };

                $followUp->setAttribute('monitor_outcome', $outcome);
                $followUp->setAttribute('acted_at', $next?->created_at);

                return $followUp;
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, FollowUp>  $classified  Output of classify().
     * @return array{on_time: int, late: int, missed: int, pending: int, upcoming: int, judged: int, on_time_rate: float|null, average_late_hours: float|null}
     */
    public function summarize(Collection $classified): array
    {
        $count = fn (FollowUpOutcome $outcome): int => $classified
            ->filter(fn (FollowUp $followUp): bool => $followUp->monitor_outcome === $outcome)
            ->count();

        $onTime = $count(FollowUpOutcome::OnTime);
        $late = $count(FollowUpOutcome::Late);
        $missed = $count(FollowUpOutcome::Missed);
        $judged = $onTime + $late + $missed;

        $lateHours = $classified
            ->filter(fn (FollowUp $followUp): bool => $followUp->monitor_outcome === FollowUpOutcome::Late)
            ->map(fn (FollowUp $followUp): float => abs($followUp->next_follow_up_date->diffInMinutes($followUp->acted_at)) / 60);

        return [
            'on_time' => $onTime,
            'late' => $late,
            'missed' => $missed,
            'pending' => $count(FollowUpOutcome::Pending),
            'upcoming' => $count(FollowUpOutcome::Upcoming),
            'judged' => $judged,
            'on_time_rate' => $judged > 0 ? round($onTime / $judged * 100, 1) : null,
            'average_late_hours' => $lateHours->isNotEmpty() ? round($lateHours->avg(), 1) : null,
        ];
    }

    /**
     * The follow-ups a supervisor is accountable for: everything for an
     * Admin, otherwise whatever was logged by somebody in their branch
     * (visibility walk, so an exited level does not hide live callers).
     *
     * @return Builder<FollowUp>
     */
    public function scopedQueryFor(User $user, string $module = self::MODULE_ALL, ?int $employeeId = null): Builder
    {
        $query = FollowUp::query();

        if ($module === self::MODULE_CUSTOMER) {
            $query->whereNull('follow_ups.lead_id');
        } elseif ($module === self::MODULE_LEAD) {
            $query->whereNotNull('follow_ups.lead_id');
        }

        if (! $user->hasRole('Admin')) {
            $query->whereIn('follow_ups.employee_id', HierarchyHelper::visibleEmployeeIds($user)->all());
        }

        if ($employeeId !== null) {
            $query->where('follow_ups.employee_id', $employeeId);
        }

        return $query;
    }

    /**
     * How long the open, already-due follow-ups in $query have been waiting.
     *
     * @param  Builder<FollowUp>  $query
     * @return array<string, int>
     */
    public function backlogAges(Builder $query, ?Carbon $now = null): array
    {
        $now ??= now();

        $buckets = ['Under 1 day' => 0, '1–7 days' => 0, '8–30 days' => 0, 'Over 30 days' => 0];

        $query->clone()
            ->latestPerSubject()
            ->scheduled()
            ->where('follow_ups.next_follow_up_date', '<', $now)
            ->pluck('follow_ups.next_follow_up_date')
            ->each(function ($due) use (&$buckets, $now): void {
                $days = Carbon::parse($due)->diffInDays($now);

                $bucket = match (true) {
                    $days < 1 => 'Under 1 day',
                    $days < 8 => '1–7 days',
                    $days < 31 => '8–30 days',
                    default => 'Over 30 days',
                };

                $buckets[$bucket]++;
            });

        return $buckets;
    }

    /**
     * Open follow-ups each employee has on each day between $from and $to,
     * keyed employee id (0 for none) then Y-m-d date.
     *
     * @param  Builder<FollowUp>  $query
     * @return array<int, array<string, int>>
     */
    public function openLoad(Builder $query, Carbon $from, Carbon $to): array
    {
        $load = [];

        $query->clone()
            ->latestPerSubject()
            ->scheduled()
            ->whereBetween('follow_ups.next_follow_up_date', [$from, $to])
            ->get(['follow_ups.id', 'follow_ups.employee_id', 'follow_ups.next_follow_up_date'])
            ->each(function (FollowUp $followUp) use (&$load): void {
                $employeeId = (int) $followUp->employee_id;
                $date = $followUp->next_follow_up_date->toDateString();

                $load[$employeeId][$date] = ($load[$employeeId][$date] ?? 0) + 1;
            });

        return $load;
    }

    /**
     * Employee/day pairs booked beyond DAILY_CAPACITY.
     *
     * @param  array<int, array<string, int>>  $load  Output of openLoad().
     * @return list<array{employee_id: int, date: string, count: int}>
     */
    public function overloadedDays(array $load): array
    {
        $overloaded = [];

        foreach ($load as $employeeId => $days) {
            foreach ($days as $date => $count) {
                if ($count > self::DAILY_CAPACITY) {
                    $overloaded[] = ['employee_id' => $employeeId, 'date' => $date, 'count' => $count];
                }
            }
        }

        return $overloaded;
    }

    /**
     * Reschedules a backlog across the next $days working days (Sundays
     * off), filling each owner's days up to DAILY_CAPACITY in due-date
     * order and spacing them SLOT_MINUTES apart from $startTime (or from
     * the next slot after now, when that time has already passed). Once every
     * day is full the rest go to that owner's lightest day. The owner of each
     * follow-up stays its owner even when a supervisor does the moving.
     * Returns how many follow-ups were moved.
     *
     * @param  iterable<int, FollowUp>  $followUps
     */
    public function spread(iterable $followUps, User $user, Carbon $startDate, int $days, string $startTime = '10:00', ?string $remarks = null): int
    {
        $reminders = app(FollowUpReminderService::class);

        $current = collect($followUps)
            ->map(fn (FollowUp $followUp): ?FollowUp => $reminders->currentFor($followUp))
            ->filter(fn (?FollowUp $followUp): bool => $followUp?->next_follow_up_date !== null)
            ->unique('id')
            ->sortBy('next_follow_up_date')
            ->values();

        $workingDays = $this->workingDays($startDate, max(1, $days));

        if ($current->isEmpty()) {
            return 0;
        }

        $load = $this->openLoad(
            FollowUp::query(),
            Carbon::parse($workingDays[0])->startOfDay(),
            Carbon::parse(end($workingDays))->endOfDay(),
        );

        foreach ($current as $followUp) {
            $owner = (int) $followUp->employee_id;

            $day = collect($workingDays)->first(fn (string $date): bool => ($load[$owner][$date] ?? 0) < self::DAILY_CAPACITY)
                ?? collect($workingDays)->sortBy(fn (string $date): int => $load[$owner][$date] ?? 0)->first();

            $slot = $load[$owner][$day] ?? 0;

            $firstSlot = Carbon::parse($day.' '.$startTime);

            if ($firstSlot->isPast()) {
                $firstSlot = now()->ceilMinutes(self::SLOT_MINUTES)->second(0);
            }

            $when = $firstSlot->addMinutes(min($slot, self::DAILY_CAPACITY - 1) * self::SLOT_MINUTES);

            $reminders->reschedule($followUp, $user, $when, $remarks, $followUp->employee_id);

            $load[$owner][$day] = $slot + 1;
        }

        return $current->count();
    }

    /**
     * @return list<string> Y-m-d dates, Sundays skipped, never before today.
     */
    public function workingDays(Carbon $startDate, int $days): array
    {
        $date = $startDate->copy()->max(today())->startOfDay();
        $workingDays = [];

        while (count($workingDays) < $days) {
            if (! $date->isSunday()) {
                $workingDays[] = $date->toDateString();
            }

            $date->addDay();
        }

        return $workingDays;
    }

    /**
     * Every row logged after the earliest of $rows for the same prospects,
     * oldest first and grouped by subject key — one query for the lot.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, FollowUp>  $rows
     * @return Collection<string, Collection<int, FollowUp>>
     */
    private function laterRowsBySubject(\Illuminate\Database\Eloquent\Collection $rows): Collection
    {
        $idsByColumn = collect(FollowUp::SUBJECT_COLUMNS)
            ->mapWithKeys(fn (string $column): array => [$column => $rows->pluck($column)->filter()->unique()->values()])
            ->filter(fn (Collection $ids): bool => $ids->isNotEmpty());

        if ($idsByColumn->isEmpty()) {
            return collect();
        }

        return FollowUp::query()
            ->where(function (Builder $query) use ($idsByColumn): void {
                foreach ($idsByColumn as $column => $ids) {
                    $query->orWhereIn($column, $ids);
                }
            })
            ->where('id', '>', $rows->min('id'))
            ->orderBy('id')
            ->get(['id', ...FollowUp::SUBJECT_COLUMNS, 'created_at'])
            ->groupBy(fn (FollowUp $followUp): string => $followUp->subject_key);
    }
}
