<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUps\Pages\ListFollowUps;
use App\Filament\Widgets\AssignedLeadFollowUpCalendarWidget;
use App\Filament\Widgets\CustomerFollowUpCalendarWidget;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Revising a prospect's next-follow-up date used to leave them sitting on
 * every date they had ever been scheduled for, because each revision inserts
 * a new follow-up row and the calendars plotted all of them. Only the newest
 * row is current; the rest are the follow-up log.
 */
class FollowUpRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->employee = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        $this->actingAs(User::factory()->create(['employee_id' => $this->employee->id]));
    }

    public function test_a_customer_appears_only_on_their_latest_next_follow_up_date(): void
    {
        $customer = Customer::factory()->create(['employee_id' => $this->employee->id]);

        $firstDate = now()->addDays(2)->setTime(10, 0);
        $revisedDate = now()->addDays(9)->setTime(15, 30);

        $this->logFollowUp($customer, $firstDate);
        $this->logFollowUp($customer, $revisedDate);

        $dates = $this->calendarDates(new CustomerFollowUpCalendarWidget);

        $this->assertSame(
            [$revisedDate->toDateString()],
            $dates->all(),
            'Only the revised next-follow-up date belongs on the calendar.',
        );
    }

    public function test_the_day_panel_lists_the_customer_once_on_the_revised_date(): void
    {
        $customer = Customer::factory()->create(['employee_id' => $this->employee->id]);

        $firstDate = now()->addDays(2)->setTime(10, 0);
        $revisedDate = now()->addDays(9)->setTime(15, 30);

        $this->logFollowUp($customer, $firstDate);
        $this->logFollowUp($customer, $revisedDate);

        $widget = new CustomerFollowUpCalendarWidget;

        $this->assertCount(
            0,
            $this->followUpsForDate($widget, $firstDate->toDateString()),
            'The superseded date must no longer list the customer.',
        );

        $onRevisedDate = $this->followUpsForDate($widget, $revisedDate->toDateString());

        $this->assertCount(1, $onRevisedDate);
        $this->assertSame($customer->id, $onRevisedDate->first()->customer_id);
    }

    public function test_a_follow_up_without_a_next_date_appears_on_no_calendar_day(): void
    {
        $customer = Customer::factory()->create(['employee_id' => $this->employee->id]);

        $this->logFollowUp($customer, null);

        $this->assertEmpty(
            $this->calendarDates(new CustomerFollowUpCalendarWidget)->all(),
            'A closed-out follow-up has no date to be due on, so it belongs on no day.',
        );
    }

    public function test_every_revision_is_kept_in_the_follow_up_log(): void
    {
        $customer = Customer::factory()->create(['employee_id' => $this->employee->id]);

        $dates = [
            now()->addDays(2)->setTime(10, 0),
            now()->addDays(5)->setTime(11, 0),
            now()->addDays(9)->setTime(15, 30),
        ];

        foreach ($dates as $date) {
            $this->logFollowUp($customer, $date);
        }

        $latest = FollowUp::where('customer_id', $customer->id)->latest('id')->firstOrFail();
        $history = $latest->historyForSubject();

        $this->assertCount(3, $history, 'Every follow-up stays in the log.');

        $this->assertSame(
            collect($dates)->map->format('Y-m-d H:i')->all(),
            $history->map(fn (FollowUp $entry) => $entry->next_follow_up_date->format('Y-m-d H:i'))->all(),
            'The log holds every date that was ever set, oldest first.',
        );
    }

    public function test_the_listing_shows_one_row_per_customer_with_the_total_follow_up_count(): void
    {
        $customer = Customer::factory()->create(['employee_id' => $this->employee->id]);

        $this->logFollowUp($customer, now()->addDays(2));
        $this->logFollowUp($customer, now()->addDays(5));
        $latest = $this->logFollowUp($customer, now()->addDays(9));

        $rows = Livewire::test(ListFollowUps::class)
            ->assertCanSeeTableRecords([$latest])
            ->instance()
            ->getTable()
            ->getRecords();

        $this->assertCount(1, $rows, 'A customer occupies a single row, not one per revision.');
        $this->assertSame(3, (int) $rows->first()->follow_up_count);
    }

    public function test_a_lead_rescheduled_twice_appears_only_on_its_final_date(): void
    {
        $lead = Lead::create([
            'employee_id' => $this->employee->id,
            'customer_name' => 'Rescheduled Lead',
            'mobile_no' => '9876500000',
            'follow_up_type' => 'Call',
            'status' => 'Pending',
            'remarks' => 'initial contact',
            'next_follow_up_date' => now()->addDays(3)->setTime(9, 0),
        ]);

        $lead->update([
            'status' => 'Interested',
            'next_follow_up_date' => now()->addDays(12)->setTime(16, 0),
            'remarks' => 'moved out',
        ]);

        $this->assertSame(2, FollowUp::where('lead_id', $lead->id)->count());

        $this->assertSame(
            [now()->addDays(12)->toDateString()],
            $this->calendarDates(new AssignedLeadFollowUpCalendarWidget)->all(),
        );
    }

    public function test_the_day_panel_renders_the_prospects_follow_up_log(): void
    {
        $customer = Customer::factory()->create([
            'employee_id' => $this->employee->id,
            'customer_name' => 'Meera Nair',
        ]);

        $supersededDate = now()->addDays(2)->setTime(10, 0);
        $revisedDate = now()->addDays(9)->setTime(15, 30);

        $this->logFollowUp($customer, $supersededDate);
        $this->logFollowUp($customer, $revisedDate);

        Livewire::test(CustomerFollowUpCalendarWidget::class)
            ->set('selectedDate', $revisedDate->toDateString())
            ->assertSee('Meera Nair')
            ->assertSee('view log')
            // The date that was replaced is shown as history, struck through.
            ->assertSee($supersededDate->format('d M Y h:i A'))
            ->assertSee($revisedDate->format('d M Y h:i A'));
    }

    public function test_the_listing_log_action_renders_every_revision(): void
    {
        $customer = Customer::factory()->create(['employee_id' => $this->employee->id]);

        $dates = [
            now()->addDays(2)->setTime(10, 0),
            now()->addDays(5)->setTime(11, 0),
            now()->addDays(9)->setTime(15, 30),
        ];

        foreach ($dates as $date) {
            $this->logFollowUp($customer, $date);
        }

        $latest = FollowUp::where('customer_id', $customer->id)->latest('id')->firstOrFail();

        Livewire::test(ListFollowUps::class)
            ->mountAction(TestAction::make('log')->table($latest))
            ->assertActionMounted(TestAction::make('log')->table($latest));

        // The modal body renders lazily in the browser, so the log view is
        // exercised directly with the same data the action hands it.
        $rendered = view('filament.follow-ups.history', [
            'history' => $latest->historyForSubject(),
            'current' => $latest,
        ])->render();

        $this->assertStringContainsString('Followed up 3 times', $rendered);

        foreach ($dates as $date) {
            $this->assertStringContainsString($date->format('d M Y h:i A'), $rendered);
        }
    }

    private function logFollowUp(Customer $customer, ?Carbon $nextDate): FollowUp
    {
        return FollowUp::create([
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'follow_up_type' => 'Call',
            'status' => 'Awaiting Low ROI',
            'remarks' => 'logged',
            'next_follow_up_date' => $nextDate,
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function calendarDates(object $widget): Collection
    {
        $events = $widget->fetchEvents([
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->addMonths(2)->endOfMonth()->toDateString(),
        ]);

        return collect($events)->pluck('extendedProps.date')->sort()->values();
    }

    private function followUpsForDate(object $widget, string $date): Collection
    {
        return collect((fn () => $this->followUpsForDate($date))->call($widget));
    }
}
