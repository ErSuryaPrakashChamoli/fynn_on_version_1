<?php

namespace App\Filament\Widgets\Concerns;

use App\Enums\FollowUpOutcome;
use App\Filament\Actions\FollowUpBacklogActions;
use App\Models\FollowUp;
use App\Services\FollowUpMonitorService;
use Filament\Actions\Action;
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
 * The using widget only says which follow-ups it may see.
 */
trait ShowsFollowUpOutcomes
{
    /**
     * @return Builder<FollowUp>
     */
    abstract protected function scopedFollowUpQuery(): Builder;

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

        $monitor = app(FollowUpMonitorService::class);

        $overloadedByDate = collect($monitor->overloadedDays($monitor->openLoad($this->scopedFollowUpQuery(), $start, $end)))
            ->countBy('date');

        return $monitor->classify($this->scopedFollowUpQuery(), $start, $end)
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
