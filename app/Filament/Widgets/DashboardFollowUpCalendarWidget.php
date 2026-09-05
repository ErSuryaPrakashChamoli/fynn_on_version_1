<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\FollowUp;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Saade\FilamentFullCalendar\Data\EventData;

/**
 * The Dashboard's follow-up calendar — a combined view of:
 *  - "Lead Follow-ups": the same CustomerAssignment-visible FollowUp records
 *    already shown by AssignedLeadFollowUpCalendarWidget (unchanged, inherited).
 *  - "Customer Follow-ups": FollowUp records visible via
 *    FollowUpResource::getEloquentQuery() — the exact same query and hierarchy
 *    rules behind "My Customer Follow-ups" / "My Customer Follow-up Calendar".
 *
 * Extending the existing widget (rather than duplicating it) means the
 * Lead-side query, hierarchy scoping, and calendar plumbing are reused
 * as-is; only the Customer-side query and the view are added.
 */
class DashboardFollowUpCalendarWidget extends AssignedLeadFollowUpCalendarWidget
{
    protected string $view = 'filament.widgets.dashboard-follow-up-calendar-widget';

    public function fetchEvents(array $info): array
    {
        $start = Carbon::parse($info['start']);
        $end = Carbon::parse($info['end']);

        $leadCounts = $this->leadFollowUpCountsByDate($start, $end);
        $customerCounts = $this->customerFollowUpCountsByDate($start, $end);

        $dates = collect($leadCounts)->keys()
            ->merge(collect($customerCounts)->keys())
            ->unique();

        return $dates
            ->map(function (string $date) use ($leadCounts, $customerCounts) {
                $leadCount = $leadCounts[$date] ?? 0;
                $customerCount = $customerCounts[$date] ?? 0;
                $total = $leadCount + $customerCount;

                return EventData::make()
                    ->id('day-'.$date)
                    ->title($total.' Follow-up'.($total === 1 ? '' : 's'))
                    ->start($date)
                    ->allDay(true)
                    ->backgroundColor('#4f46e5')
                    ->borderColor('#4f46e5')
                    ->extendedProps([
                        'date' => $date,
                        'count' => $total,
                        'leadCount' => $leadCount,
                        'customerCount' => $customerCount,
                    ]);
            })
            ->values()
            ->toArray();
    }

    /**
     * @return array<string, int>
     */
    protected function leadFollowUpCountsByDate(Carbon $start, Carbon $end): array
    {
        [$customerIds, $aiRecordIds, $leadIds] = $this->visibleLeadIds();

        if (empty($customerIds) && empty($aiRecordIds) && empty($leadIds)) {
            return [];
        }

        $query = FollowUp::query();
        $this->scopeToVisibleLeadFollowUps($query, $customerIds, $aiRecordIds, $leadIds);

        return $query
            ->latestPerSubject()
            ->scheduled()
            ->whereBetween('next_follow_up_date', [$start, $end])
            ->get(['next_follow_up_date'])
            ->groupBy(fn (FollowUp $followUp) => $followUp->next_follow_up_date->toDateString())
            ->map->count()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    protected function customerFollowUpCountsByDate(Carbon $start, Carbon $end): array
    {
        return FollowUpResource::getEloquentQuery()
            ->latestPerSubject()
            ->scheduled()
            ->whereBetween('next_follow_up_date', [$start, $end])
            ->get(['next_follow_up_date'])
            ->groupBy(fn (FollowUp $followUp) => $followUp->next_follow_up_date->toDateString())
            ->map->count()
            ->all();
    }

    public function customerFollowUpsForDate(string $date): Collection
    {
        return FollowUpResource::getEloquentQuery()
            ->latestPerSubject()
            ->scheduled()
            ->whereDate('next_follow_up_date', $date)
            ->with(['customer', 'aiCustomerRecord', 'employee'])
            ->orderBy('next_follow_up_date')
            ->get();
    }

    public function leadFollowUpsForDate(string $date): Collection
    {
        return $this->followUpsForDate($date);
    }
}
