<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\FollowUp;
use App\Services\FollowUpMonitorService;
use App\Support\SelectedMonth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Dashboard's follow-up calendar — every user's home view of their own
 * follow-ups (a caller their own, a supervisor their branch, Admin all),
 * combining:
 *  - "Lead Follow-ups": the same CustomerAssignment-visible FollowUp records
 *    shown by AssignedLeadFollowUpCalendarWidget (inherited).
 *  - "Customer Follow-ups": FollowUp records visible via
 *    FollowUpResource::getEloquentQuery() — the exact same query and hierarchy
 *    rules behind "My Customer Follow-ups" / "My Customer Follow-up Calendar".
 *
 * Like both calendars, each day shows what became of the follow-ups due on
 * it (on time / late / missed / open, see ShowsFollowUpOutcomes), and the
 * day panel offers to spread or drop that day's missed ones.
 */
class DashboardFollowUpCalendarWidget extends AssignedLeadFollowUpCalendarWidget
{
    protected string $view = 'filament.widgets.dashboard-follow-up-calendar-widget';

    /**
     * The Dashboard's first screen: render with the page, not in a second
     * round trip.
     */
    protected static bool $isLazy = false;

    public const CALENDAR_VIEWS = ['month', 'week', 'day'];

    /**
     * The grid is drawn on the server (see the view), not by the
     * FullCalendar script: the grid and its day chips then arrive in the
     * page itself, instead of the card sitting empty while a 1.4 MB script
     * loads and the chips following in a further round trip. 'month' shows
     * only the weeks the month spans, its neighbours' days left blank.
     */
    public string $calendarView = 'month';

    /** Any date inside the period on screen (Y-m-d). */
    public string $calendarDate = '';

    public function mount(): void
    {
        parent::mount();

        $this->calendarDate = SelectedMonth::current()->toDateString();
    }

    public function switchCalendarView(string $view): void
    {
        if (! in_array($view, self::CALENDAR_VIEWS, true)) {
            return;
        }

        // Week / day open on the picked day when it is on screen.
        [$start, $end] = $this->calendarPeriod();

        if ($this->selectedDate && Carbon::parse($this->selectedDate)->betweenIncluded($start, $end)) {
            $this->calendarDate = $this->selectedDate;
        }

        $this->calendarView = $view;
    }

    public function showPreviousPeriod(): void
    {
        $this->calendarDate = $this->shiftedAnchor(-1)->toDateString();
    }

    public function showNextPeriod(): void
    {
        $this->calendarDate = $this->shiftedAnchor(1)->toDateString();
    }

    public function showTodayPeriod(): void
    {
        $this->calendarDate = today()->toDateString();
    }

    public function selectCalendarDate(string $date): void
    {
        $this->selectedDate = Carbon::parse($date)->toDateString();
    }

    /**
     * First and last day of the period on screen.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function calendarPeriod(): array
    {
        $anchor = $this->anchor();

        return match ($this->calendarView) {
            'week' => [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay()],
            'day' => [$anchor->copy(), $anchor->copy()],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()->startOfDay()],
        };
    }

    public function calendarTitle(): string
    {
        [$start, $end] = $this->calendarPeriod();

        if ($start->isSameMonth($end)) {
            return $start->format('F Y');
        }

        return $start->format('M').' – '.$end->format('M Y');
    }

    /**
     * The grid: one row per week (a single row for week / day), each cell
     * with its date, whether it belongs to the period, and its day chip.
     *
     * @return array{columns: array<int, string>, weeks: array<int, array<int, array{date: string, day: int, inPeriod: bool, isToday: bool, isWeekend: bool, event: ?array<string, mixed>}>>}
     */
    public function calendarGrid(): array
    {
        [$start, $end] = $this->calendarPeriod();

        $events = collect($this->fetchEvents([
            'start' => $start->toDateString(),
            'end' => $end->copy()->addDay()->toDateString(),
        ]))->keyBy('extendedProps.date');

        [$gridStart, $gridEnd] = $this->calendarView === 'month'
            ? [$start->copy()->startOfWeek(Carbon::MONDAY), $end->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay()]
            : [$start, $end];

        $cells = [];

        for ($day = $gridStart->copy(); $day->lte($gridEnd); $day->addDay()) {
            $date = $day->toDateString();
            $inPeriod = $day->betweenIncluded($start, $end);

            $cells[] = [
                'date' => $date,
                'day' => $day->day,
                'inPeriod' => $inPeriod,
                'isToday' => $day->isToday(),
                'isWeekend' => $day->isWeekend(),
                'event' => $inPeriod ? ($events[$date] ?? null) : null,
            ];
        }

        return [
            'columns' => collect($cells)->take($this->calendarView === 'day' ? 1 : 7)
                ->map(fn (array $cell): string => Carbon::parse($cell['date'])->format('D'))
                ->all(),
            'weeks' => array_chunk($cells, $this->calendarView === 'day' ? 1 : 7),
        ];
    }

    public function isTodayOnScreen(): bool
    {
        [$start, $end] = $this->calendarPeriod();

        return today()->betweenIncluded($start, $end);
    }

    private function anchor(): Carbon
    {
        return Carbon::parse($this->calendarDate ?: SelectedMonth::current())->startOfDay();
    }

    private function shiftedAnchor(int $direction): Carbon
    {
        return match ($this->calendarView) {
            'week' => $this->anchor()->addWeeks($direction),
            'day' => $this->anchor()->addDays($direction),
            default => $this->anchor()->startOfMonth()->addMonthsNoOverflow($direction),
        };
    }

    /**
     * Lead-side and customer-side follow-ups together. A follow-up on an
     * assigned AI record is visible from both sides; matching on id keeps
     * it to one count on the calendar.
     */
    protected function scopedFollowUpQuery(): Builder
    {
        $leadIds = parent::scopedFollowUpQuery()->select('follow_ups.id');
        $customerIds = FollowUpResource::getEloquentQuery()->select('follow_ups.id');

        return FollowUp::query()->where(fn (Builder $query) => $query
            ->whereIn('follow_ups.id', $leadIds)
            ->orWhereIn('follow_ups.id', $customerIds));
    }

    /**
     * @return Collection<int, FollowUp>
     */
    public function leadFollowUpsForDate(string $date): Collection
    {
        return $this->classifiedForDate(parent::scopedFollowUpQuery(), $date);
    }

    /**
     * @return Collection<int, FollowUp>
     */
    public function customerFollowUpsForDate(string $date): Collection
    {
        return $this->classifiedForDate(FollowUpResource::getEloquentQuery(), $date);
    }

    /**
     * @param  Builder<FollowUp>  $query
     * @return Collection<int, FollowUp>
     */
    private function classifiedForDate(Builder $query, string $date): Collection
    {
        $day = Carbon::parse($date);

        return app(FollowUpMonitorService::class)
            ->classify(
                $query->with(['customer', 'aiCustomerRecord', 'lead', 'employee']),
                $day->copy()->startOfDay(),
                $day->copy()->endOfDay(),
            )
            ->sortBy('next_follow_up_date')
            ->values();
    }
}
