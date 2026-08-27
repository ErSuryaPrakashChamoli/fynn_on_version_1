<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\FollowUp;
use App\Support\SelectedMonth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Saade\FilamentFullCalendar\Data\EventData;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * "My Customer Follow-up Calendar" — a calendar view over the exact same
 * "Follow Ups" data and visibility rules as FollowUpResource ("My Customer
 * Follow-ups"). Visibility is inherited by calling
 * FollowUpResource::getEloquentQuery() directly rather than re-implementing
 * the hierarchy logic here, so the two stay identical by construction.
 */
class CustomerFollowUpCalendarWidget extends FullCalendarWidget
{
    public Model|string|null $model = FollowUp::class;

    protected string $view = 'filament.widgets.customer-follow-up-calendar-widget';

    public ?string $selectedDate = null;

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
    }

    public function config(): array
    {
        return [
            'initialDate' => SelectedMonth::current()->toDateString(),
            'firstDay' => 1,
            'height' => 'auto',
            'headerToolbar' => [
                'left' => 'dayGridMonth,dayGridWeek,dayGridDay',
                'center' => 'title',
                'right' => 'prev,next today',
            ],
            'titleFormat' => [
                'year' => 'numeric',
                'month' => 'long',
            ],
            'buttonText' => [
                'today' => 'Today',
                'month' => 'Month',
                'week' => 'Week',
                'day' => 'Day',
            ],
        ];
    }

    /**
     * Reuses the same day-summary chip styling already built for the
     * dashboard's follow-up calendar (see resources/css/filament/admin/theme.css),
     * so both calendars share one visual language with no extra CSS.
     */
    public function eventClassNames(): string
    {
        return <<<'JS'
            function ({ event }) {
                return ['lead-followup-day-chip'];
            }
        JS;
    }

    public function eventContent(): string
    {
        return <<<'JS'
            function ({ event }) {
                const count = event.extendedProps.count ?? 0;
                const label = count === 1 ? '1 Follow-up' : count + ' Follow-ups';

                return {
                    html: `
                        <div class="lead-followup-day-chip__inner">
                            <svg class="lead-followup-day-chip__icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zM3.5 8.5v6.75c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25V8.5h-13z" clip-rule="evenodd" />
                            </svg>
                            <span>${label}</span>
                        </div>
                    `,
                };
            }
        JS;
    }

    public function eventDidMount(): string
    {
        return <<<'JS'
            function ({ el }) {
                el.closest('.fc-daygrid-day')?.classList.add('has-followups');
            }
        JS;
    }

    protected function headerActions(): array
    {
        return [];
    }

    public function fetchEvents(array $info): array
    {
        $start = Carbon::parse($info['start']);
        $end = Carbon::parse($info['end']);

        return FollowUpResource::getEloquentQuery()
            ->whereRaw('COALESCE(next_follow_up_date, follow_up_date) BETWEEN ? AND ?', [$start, $end])
            ->get(['id', 'next_follow_up_date', 'follow_up_date'])
            ->groupBy(fn (FollowUp $followUp) => Carbon::parse($followUp->next_follow_up_date ?? $followUp->follow_up_date)->toDateString())
            ->map(function (\Illuminate\Support\Collection $followUpsForDay, string $date) {
                $count = $followUpsForDay->count();

                return EventData::make()
                    ->id('day-'.$date)
                    ->title($count.' Follow-up'.($count === 1 ? '' : 's'))
                    ->start($date)
                    ->allDay(true)
                    ->backgroundColor('#4f46e5')
                    ->borderColor('#4f46e5')
                    ->extendedProps([
                        'date' => $date,
                        'count' => $count,
                    ]);
            })
            ->values()
            ->toArray();
    }

    public function onEventClick(array $event): void
    {
        $this->selectedDate = $event['extendedProps']['date']
            ?? Carbon::parse($event['start'])->toDateString();
    }

    public function onDateSelect(string $start, ?string $end, bool $allDay, ?array $view, ?array $resource): void
    {
        $this->selectedDate = Carbon::parse($start)->toDateString();
    }

    protected function followUpsForDate(string $date): Collection
    {
        return FollowUpResource::getEloquentQuery()
            ->whereRaw('DATE(COALESCE(next_follow_up_date, follow_up_date)) = ?', [$date])
            ->with(['customer', 'aiCustomerRecord', 'employee'])
            ->orderByRaw('COALESCE(next_follow_up_date, follow_up_date)')
            ->get();
    }

    public function getFormSchema(): array
    {
        return [];
    }
}
