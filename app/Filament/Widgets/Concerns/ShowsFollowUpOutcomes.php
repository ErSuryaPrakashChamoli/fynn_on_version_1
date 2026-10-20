<?php

namespace App\Filament\Widgets\Concerns;

use App\Enums\FollowUpOutcome;
use App\Filament\Actions\FollowUpBacklogActions;
use App\Filament\Pages\FollowUpOutcomeListPage;
use App\Models\FollowUp;
use App\Services\FollowUpMonitorService;
use App\Support\DueDateInput;
use App\Support\FollowUpCalendarCache;
use App\Support\SelectedMonth;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Saade\FilamentFullCalendar\Data\EventData;

/**
 * Turns a follow-up calendar from "how many are on this day" into "what
 * happened on this day": each day shows how many follow-ups were kept on
 * time, kept late, missed, and are still open, and warns when a caller is
 * booked past FollowUpMonitorService::DAILY_CAPACITY. The day panel lists
 * every follow-up that fell due that day with its verdict, and offers to
 * spread or drop the missed ones.
 *
 * The legend's On time / Late / Missed / Open chips open the outcome list:
 * every follow-up with that verdict whose due date falls in a date range,
 * starting with the period the calendar opens on.
 *
 * The using widget only says which follow-ups it may see.
 */
trait ShowsFollowUpOutcomes
{
    /** Cards the outcome list shows at first, and adds per "Show more". */
    public const OUTCOME_LIST_PAGE_SIZE = 25;

    /** The legend chip whose follow-ups are listed (a key of outcomeListFilters()), or null while the list is closed. */
    public ?string $outcomeListFilter = null;

    /** First due date in the outcome list (Y-m-d). */
    public ?string $outcomeListFrom = null;

    /** Last due date in the outcome list (Y-m-d). */
    public ?string $outcomeListTo = null;

    public int $outcomeListLimit = self::OUTCOME_LIST_PAGE_SIZE;

    /**
     * @return Builder<FollowUp>
     */
    abstract protected function scopedFollowUpQuery(): Builder;

    /**
     * The legend chips that open the outcome list, with the verdicts each
     * one counts on the day chips.
     *
     * @return array<string, array{label: string, color: string, outcomes: list<FollowUpOutcome>}>
     */
    public static function outcomeListFilters(): array
    {
        return FollowUpOutcome::legendGroups();
    }

    public function outcomeListModalId(): string
    {
        return 'follow-up-outcome-list-'.$this->getId();
    }

    /**
     * Lists the follow-ups behind a legend chip. Opening the list starts
     * from the calendar's period; switching chips inside it keeps the dates
     * already picked.
     */
    public function openOutcomeList(string $filter): void
    {
        if (! array_key_exists($filter, static::outcomeListFilters())) {
            return;
        }

        if ($this->outcomeListFilter === null) {
            [$from, $to] = $this->outcomeListDefaultPeriod();

            $this->outcomeListFrom = $from->toDateString();
            $this->outcomeListTo = $to->toDateString();
        }

        $this->outcomeListFilter = $filter;
        $this->outcomeListLimit = self::OUTCOME_LIST_PAGE_SIZE;
    }

    /**
     * Called when the list's modal closes, so later re-renders of the
     * calendar stop building the list.
     */
    public function closeOutcomeList(): void
    {
        $this->outcomeListFilter = null;
        $this->outcomeListFrom = null;
        $this->outcomeListTo = null;
        $this->outcomeListLimit = self::OUTCOME_LIST_PAGE_SIZE;
    }

    public function updatedOutcomeListFrom(): void
    {
        $this->outcomeListLimit = self::OUTCOME_LIST_PAGE_SIZE;
    }

    public function updatedOutcomeListTo(): void
    {
        $this->outcomeListLimit = self::OUTCOME_LIST_PAGE_SIZE;
    }

    public function showMoreOutcomeList(): void
    {
        $this->outcomeListLimit = max($this->outcomeListLimit, self::OUTCOME_LIST_PAGE_SIZE) + self::OUTCOME_LIST_PAGE_SIZE;
    }

    /**
     * The due-date range the list covers. A blank or unreadable date falls
     * back to the calendar's period; a "to" before "from" is read as "from".
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function outcomeListPeriod(): array
    {
        [$defaultFrom, $defaultTo] = $this->outcomeListDefaultPeriod();

        $from = DueDateInput::parse($this->outcomeListFrom) ?? $defaultFrom;
        $to = DueDateInput::parse($this->outcomeListTo) ?? $defaultTo;

        if ($to->lt($from)) {
            $to = $from->copy();
        }

        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }

    /**
     * The follow-ups behind the picked legend chip, one list per source
     * (see outcomeListSources()). Each list holds the first
     * $outcomeListLimit matches, earliest due first, loaded for the cards;
     * total is how many match in all.
     *
     * @return array<string, array{label: string, listing: class-string<FollowUpOutcomeListPage>|null, total: int, followUps: Collection<int, FollowUp>}>
     */
    public function outcomeList(): array
    {
        $filter = static::outcomeListFilters()[$this->outcomeListFilter ?? ''] ?? null;

        if ($filter === null) {
            return [];
        }

        [$from, $to] = $this->outcomeListPeriod();

        return collect($this->outcomeListSources())
            ->map(fn (array $source): array => [
                'label' => $source['label'],
                'listing' => $source['listing'],
                ...$this->followUpsWithOutcome($source['query'], $filter['outcomes'], $from, $to),
            ])
            ->all();
    }

    /**
     * Where the outcome list draws from, one tab each, and the full listing
     * page each tab links to. By default the calendar's own follow-ups as a
     * single list.
     *
     * @return array<string, array{label: string, query: Builder<FollowUp>, listing: class-string<FollowUpOutcomeListPage>|null}>
     */
    protected function outcomeListSources(): array
    {
        return [
            'follow-ups' => ['label' => 'Follow-ups', 'query' => $this->scopedFollowUpQuery(), 'listing' => $this->outcomeListingPage()],
        ];
    }

    /**
     * The Lead or Customer follow-up outcome listing holding the same
     * follow-ups as this calendar, if any.
     *
     * @return class-string<FollowUpOutcomeListPage>|null
     */
    protected function outcomeListingPage(): ?string
    {
        return null;
    }

    /**
     * The range the outcome list opens on: the month the calendar opens on.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function outcomeListDefaultPeriod(): array
    {
        $month = SelectedMonth::current();

        return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
    }

    /**
     * Matches are judged on the few columns classify() needs; only the
     * page of cards on screen is then loaded with its prospect and owner.
     *
     * @param  Builder<FollowUp>  $query
     * @param  list<FollowUpOutcome>  $outcomes
     * @return array{total: int, followUps: Collection<int, FollowUp>}
     */
    protected function followUpsWithOutcome(Builder $query, array $outcomes, Carbon $from, Carbon $to): array
    {
        $columns = collect(['id', 'employee_id', 'next_follow_up_date', 'created_at', ...FollowUp::SUBJECT_COLUMNS])
            ->map(fn (string $column): string => 'follow_ups.'.$column)
            ->all();

        $matching = app(FollowUpMonitorService::class)
            ->classify($query->clone()->select($columns), $from, $to)
            ->filter(fn (FollowUp $followUp): bool => in_array($followUp->monitor_outcome, $outcomes, true))
            ->sortBy([
                fn (FollowUp $a, FollowUp $b): int => $a->next_follow_up_date <=> $b->next_follow_up_date,
                fn (FollowUp $a, FollowUp $b): int => $a->id <=> $b->id,
            ])
            ->values();

        $shown = $matching->take(max($this->outcomeListLimit, self::OUTCOME_LIST_PAGE_SIZE));

        $records = FollowUp::query()
            ->with(['customer', 'aiCustomerRecord', 'lead', 'employee'])
            ->findMany($shown->pluck('id'))
            ->keyBy('id');

        $followUps = $shown
            ->map(function (FollowUp $classified) use ($records): ?FollowUp {
                $followUp = $records->get($classified->id);

                $followUp?->setAttribute('monitor_outcome', $classified->monitor_outcome);
                $followUp?->setAttribute('acted_at', $classified->acted_at);

                return $followUp;
            })
            ->filter()
            ->values();

        return ['total' => $matching->count(), 'followUps' => $followUps];
    }

    public function eventClassNames(): string
    {
        return <<<'JS'
            function ({ event }) {
                return ['lead-followup-day-chip'];
            }
        JS;
    }

    /**
     * One chip per day, split into on-time / late / missed / open segments.
     * Events without the outcome props (the Dashboard calendar) keep the
     * plain count chip.
     */
    public function eventContent(): string
    {
        return <<<'JS'
            function ({ event }) {
                const props = event.extendedProps;

                if (props.onTime === undefined) {
                    const count = props.count ?? 0;

                    return { html: `<div class="lead-followup-day-chip__inner"><span>${count === 1 ? '1 Follow-up' : count + ' Follow-ups'}</span></div>` };
                }

                const parts = [];

                if (props.onTime) parts.push(`<span class="followup-outcome followup-outcome--on-time" title="Kept on time">✓ ${props.onTime}</span>`);
                if (props.late) parts.push(`<span class="followup-outcome followup-outcome--late" title="Kept late">⏱ ${props.late}</span>`);
                if (props.missed) parts.push(`<span class="followup-outcome followup-outcome--missed" title="Missed">✗ ${props.missed}</span>`);
                if (props.open) parts.push(`<span class="followup-outcome followup-outcome--open" title="Still open">${props.open} open</span>`);
                if (props.overloaded) parts.push(`<span class="followup-outcome followup-outcome--overloaded" title="${props.overloaded} caller(s) booked past ${props.capacity} a day">⚠ ${props.overloaded}</span>`);

                return { html: `<div class="followup-outcome-chip">${parts.join('')}</div>` };
            }
        JS;
    }

    public function eventDidMount(): string
    {
        return <<<'JS'
            function ({ el, event }) {
                const cell = el.closest('.fc-daygrid-day');

                cell?.classList.add('has-followups');

                if (event.extendedProps.missed) {
                    cell?.classList.add('has-missed-followups');
                }
            }
        JS;
    }

    public function fetchEvents(array $info): array
    {
        $start = Carbon::parse($info['start'])->startOfDay();
        $end = Carbon::parse($info['end'])->endOfDay();

        return FollowUpCalendarCache::remember(
            static::class,
            Filament::auth()->id(),
            $start->toDateTimeString(),
            $end->toDateTimeString(),
            fn (): array => $this->buildDayEvents($start, $end),
        );
    }

    /**
     * One chip per day between $start and $end. Only the columns the
     * verdicts need are loaded — the chips never show remarks or statuses.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildDayEvents(Carbon $start, Carbon $end): array
    {
        $monitor = app(FollowUpMonitorService::class);

        $overloadedByDate = collect($monitor->overloadedDays($monitor->openLoad($this->scopedFollowUpQuery(), $start, $end)))
            ->countBy('date');

        $columns = collect(['id', 'employee_id', 'next_follow_up_date', 'created_at', ...FollowUp::SUBJECT_COLUMNS])
            ->map(fn (string $column): string => 'follow_ups.'.$column)
            ->all();

        return $monitor->classify($this->scopedFollowUpQuery()->select($columns), $start, $end)
            ->groupBy(fn (FollowUp $followUp): string => $followUp->next_follow_up_date->toDateString())
            ->map(function (Collection $followUpsForDay, string $date) use ($overloadedByDate): array {
                $count = fn (FollowUpOutcome ...$outcomes): int => $followUpsForDay
                    ->filter(fn (FollowUp $followUp): bool => in_array($followUp->monitor_outcome, $outcomes, true))
                    ->count();

                return EventData::make()
                    ->id('day-'.$date)
                    ->title($followUpsForDay->count().' Follow-up'.($followUpsForDay->count() === 1 ? '' : 's'))
                    ->start($date)
                    ->allDay(true)
                    ->backgroundColor('#4f46e5')
                    ->borderColor('#4f46e5')
                    ->extendedProps([
                        'date' => $date,
                        'count' => $followUpsForDay->count(),
                        'onTime' => $count(FollowUpOutcome::OnTime),
                        'late' => $count(FollowUpOutcome::Late),
                        'missed' => $count(FollowUpOutcome::Missed),
                        'open' => $count(FollowUpOutcome::Pending, FollowUpOutcome::Upcoming),
                        'overloaded' => $overloadedByDate[$date] ?? 0,
                        'capacity' => FollowUpMonitorService::DAILY_CAPACITY,
                    ])
                    ->toArray();
            })
            ->values()
            ->all();
    }

    /**
     * Every follow-up that fell (or falls) due on $date, with its verdict.
     *
     * @return Collection<int, FollowUp>
     */
    protected function followUpsForDate(string $date): Collection
    {
        $day = Carbon::parse($date);

        return app(FollowUpMonitorService::class)
            ->classify(
                $this->scopedFollowUpQuery()->with(['customer', 'aiCustomerRecord', 'lead', 'employee']),
                $day->copy()->startOfDay(),
                $day->copy()->endOfDay(),
            )
            ->sortBy('next_follow_up_date')
            ->values();
    }

    /**
     * @return Collection<int, FollowUp>
     */
    public function missedFollowUpsForSelectedDate(): Collection
    {
        if (blank($this->selectedDate)) {
            return collect();
        }

        return $this->followUpsForDate($this->selectedDate)
            ->filter(fn (FollowUp $followUp): bool => $followUp->monitor_outcome === FollowUpOutcome::Missed)
            ->values();
    }

    public function spreadMissedAction(): Action
    {
        return FollowUpBacklogActions::spread(
            'spreadMissed',
            fn (): Collection => $this->missedFollowUpsForSelectedDate(),
            fn () => $this->refreshRecords(),
        );
    }

    public function dropMissedAction(): Action
    {
        return FollowUpBacklogActions::drop(
            'dropMissed',
            fn (): Collection => $this->missedFollowUpsForSelectedDate(),
            fn () => $this->refreshRecords(),
        );
    }
}
