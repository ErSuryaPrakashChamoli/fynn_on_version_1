<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Filament\Widgets\Concerns\ShowsFollowUpOutcomes;
use App\Models\FollowUp;
use App\Support\SelectedMonth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * "My Customer Follow-up Calendar" — a calendar view over the exact same
 * "Follow Ups" data and visibility rules as FollowUpResource ("My Customer
 * Follow-ups"). Visibility is inherited by calling
 * FollowUpResource::getEloquentQuery() directly rather than re-implementing
 * the hierarchy logic here, so the two stay identical by construction.
 *
 * Each day shows what became of the follow-ups due on it — kept on time,
 * kept late, missed, still open — see ShowsFollowUpOutcomes.
 */
class CustomerFollowUpCalendarWidget extends FullCalendarWidget
{
    use ShowsFollowUpOutcomes;

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

    protected function headerActions(): array
    {
        return [];
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

    protected function scopedFollowUpQuery(): Builder
    {
        return FollowUpResource::getEloquentQuery();
    }

    public function getFormSchema(): array
    {
        return [];
    }
}
