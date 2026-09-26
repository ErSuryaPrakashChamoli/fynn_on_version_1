<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\FollowUp;
use App\Services\FollowUpMonitorService;
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
