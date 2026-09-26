<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ShowsFollowUpOutcomes;
use App\Models\Bank;
use App\Models\CustomerAssignment;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Services\HierarchyService;
use App\Support\SelectedMonth;
use Carbon\Carbon;
use Coolsam\Flatpickr\Forms\Components\Flatpickr;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Saade\FilamentFullCalendar\Actions\CreateAction;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * Lead follow-up calendar. Each day shows what became of the follow-ups due
 * on it — kept on time, kept late, missed, still open — see
 * ShowsFollowUpOutcomes.
 */
class AssignedLeadFollowUpCalendarWidget extends FullCalendarWidget
{
    use ShowsFollowUpOutcomes;

    public Model|string|null $model = FollowUp::class;

    protected string $view = 'filament.widgets.assigned-lead-follow-up-calendar-widget';

    public ?string $selectedDate = null;

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
    }

    public function config(): array
    {
        return [
            // Opens the calendar directly on the globally selected month —
            // prev/next/today navigation from there is unaffected.
            'initialDate' => SelectedMonth::current()->toDateString(),
            'firstDay' => 1,
            // Lets the calendar grow to fit its rows instead of being
            // squeezed into an aspect-ratio-derived height, which was
            // forcing FullCalendar's internal scroller to kick in once the
            // "has-followups" day styling made rows taller than that.
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
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Triggered when the user clicks a day's aggregated follow-up count.
     * Each event represents a whole day rather than a single FollowUp record,
     * so this bypasses the package's default record-resolving click handler.
     */
    public function onEventClick(array $event): void
    {
        $this->selectedDate = $event['extendedProps']['date']
            ?? Carbon::parse($event['start'])->toDateString();
    }

    /**
     * Triggered when the user clicks/selects a date cell itself (not an
     * event chip). Clicking any date — with or without follow-ups — just
     * updates the side panel, instead of the package's default "create" form.
     */
    public function onDateSelect(string $start, ?string $end, bool $allDay, ?array $view, ?array $resource): void
    {
        $this->selectedDate = Carbon::parse($start)->toDateString();
    }

    /**
     * Follow-ups on the assigned leads and raw leads this user can see.
     */
    protected function scopedFollowUpQuery(): Builder
    {
        [$customerIds, $aiRecordIds, $leadIds] = $this->visibleLeadIds();

        $query = FollowUp::query();

        if (empty($customerIds) && empty($aiRecordIds) && empty($leadIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $this->scopeToVisibleLeadFollowUps($query, $customerIds, $aiRecordIds, $leadIds);
    }

    /**
     * Scopes a FollowUp query to those tied to the given visible
     * customers, AI-extracted records, or raw Leads. Shared by
     * fetchEvents/followUpsForDate here and by
     * DashboardFollowUpCalendarWidget's lead-side counts.
     *
     * @param  array<int, int>  $customerIds
     * @param  array<int, int>  $aiRecordIds
     * @param  array<int, int>  $leadIds
     */
    protected function scopeToVisibleLeadFollowUps(Builder $query, array $customerIds, array $aiRecordIds, array $leadIds): Builder
    {
        return $query->where(function (Builder $query) use ($customerIds, $aiRecordIds, $leadIds) {
            $query->when(filled($customerIds), fn (Builder $q) => $q->whereIn('customer_id', $customerIds))
                ->when(filled($aiRecordIds), fn (Builder $q) => $q->orWhereIn('ai_customer_record_id', $aiRecordIds))
                ->when(filled($leadIds), fn (Builder $q) => $q->orWhereIn('lead_id', $leadIds));
        });
    }

    /**
     * FullCalendarWidget's $record property is uninitialized (not just null)
     * until an Edit/View action mounts one, so calling getRecord() from a
     * CreateAction context throws. isset() is the safe way to probe an
     * uninitialized typed property without triggering that error.
     */
    protected function currentRecord(): ?Model
    {
        return isset($this->record) ? $this->getRecord() : null;
    }

    public function getFormSchema(): array
    {
        return [
            Select::make('assignment_selector')
                ->label('Assigned Lead')
                ->options(fn () => $this->assignmentOptions())
                ->searchable()
                ->live()
                ->required(fn () => ! $this->currentRecord())
                ->visible(fn () => ! $this->currentRecord())
                ->dehydrated(false)
                ->afterStateUpdated(function ($state, $set) {
                    $assignment = CustomerAssignment::find($state);

                    $set('customer_id', $assignment?->customer_id);
                    $set('ai_customer_record_id', $assignment?->ai_customer_record_id);
                }),

            Placeholder::make('lead_name')
                ->label('Assigned Lead')
                ->content(fn () => $this->currentRecord()?->display_name)
                ->visible(fn () => (bool) $this->currentRecord()),

            Select::make('follow_up_type')
                ->options([
                    'Call' => 'Call',
                    'WhatsApp' => 'WhatsApp',
                    'Email' => 'Email',
                    'Visit' => 'Visit',
                ])
                ->required(),

            Select::make('status')
                ->label('Status')
                ->options(CustomerAssignment::FOLLOW_UP_STATUSES)
                ->default('Pending')
                ->live()
                ->required()
                ->afterStateUpdated(function ($state, $set) {
                    if (in_array($state, CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES)) {
                        $set('next_follow_up_date', null);
                    }

                    if ($state !== 'Eligible for Other Bank') {
                        $set('bank_id', null);
                    }
                }),

            Select::make('bank_id')
                ->label('Bank Name')
                ->options(
                    fn () => Bank::query()
                        ->where('is_active', 1)
                        ->orderBy('bank_name')
                        ->pluck('bank_name', 'id')
                        ->toArray()
                )
                ->searchable()
                ->preload()
                ->required(fn (Get $get) => $get('status') === 'Eligible for Other Bank')
                ->visible(fn (Get $get) => $get('status') === 'Eligible for Other Bank')
                ->live(),

            Flatpickr::make('next_follow_up_date')
                ->label('Next Follow Up Date & Time')
                ->time(true)
                ->time24hr(false)
                ->seconds(false)
                ->minuteIncrement(15)
                ->format('Y-m-d H:i')
                ->displayFormat('d M Y h:i K')
                ->minDate(today())
                ->required(fn (Get $get) => ! in_array($get('status'), CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES))
                ->visible(fn (Get $get) => ! in_array($get('status'), CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES))
                ->placeholder('Select date & time')
                ->suffixIcon('heroicon-m-calendar'),

            Textarea::make('remarks')
                ->rows(4)
                ->required()
                ->columnSpanFull(),

            Hidden::make('customer_id')->dehydrated(true),
            Hidden::make('ai_customer_record_id')->dehydrated(true),

            Hidden::make('employee_id')
                ->default(fn () => Filament::auth()->user()?->employee?->id)
                ->dehydrated(true),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function assignmentOptions(): array
    {
        return $this->visibleAssignmentsQuery()
            ->with(['customer', 'aiCustomerRecord.schema'])
            ->get()
            ->mapWithKeys(fn (CustomerAssignment $assignment) => [$assignment->id => $assignment->display_name])
            ->toArray();
    }

    /**
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>}
     */
    protected function visibleLeadIds(): array
    {
        $assignments = $this->visibleAssignmentsQuery()
            ->get(['customer_id', 'ai_customer_record_id']);

        return [
            $assignments->pluck('customer_id')->filter()->unique()->values()->all(),
            $assignments->pluck('ai_customer_record_id')->filter()->unique()->values()->all(),
            $this->visibleLeadsQuery()->pluck('id')->all(),
        ];
    }

    protected function visibleAssignmentsQuery(): Builder
    {
        $query = CustomerAssignment::query();

        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('Admin')) {
            return $query;
        }

        return $query->whereIn('employee_id', HierarchyService::visibleEmployeeIds($user));
    }

    protected function visibleLeadsQuery(): Builder
    {
        $query = Lead::query();

        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('Admin')) {
            return $query;
        }

        return $query->whereIn('employee_id', HierarchyService::visibleEmployeeIds($user));
    }
}
