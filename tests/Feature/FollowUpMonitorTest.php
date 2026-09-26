<?php

namespace Tests\Feature;

use App\Enums\FollowUpOutcome;
use App\Filament\Pages\FollowUpMonitor;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Widgets\CustomerFollowUpCalendarWidget;
use App\Filament\Widgets\DashboardFollowUpCalendarWidget;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\FollowUpMonitorService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Follow-up outcomes (on time / late / missed / pending), the calendars'
 * outcome chips and missed-backlog actions, and the supervisors' Follow-up
 * Monitor page.
 */
class FollowUpMonitorTest extends TestCase
{
    use RefreshDatabase;

    private Employee $teamLeader;

    private Employee $caller;

    private Employee $otherCaller;

    private FollowUpMonitorService $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Wednesday noon.
        $this->travelTo(Carbon::parse('2026-09-16 12:00:00'));

        $this->teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'exit_status' => 'no']);
        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'exit_status' => 'no',
        ]);
        $this->otherCaller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);

        $this->monitor = app(FollowUpMonitorService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Outcomes
    |--------------------------------------------------------------------------
    */

    public function test_acting_on_the_due_day_is_on_time(): void
    {
        $customer = $this->customer();
        $this->logFollowUp($customer, Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($customer, null, createdAt: Carbon::parse('2026-09-10 18:00'));

        $this->assertSame(FollowUpOutcome::OnTime, $this->outcomeOfFirstRow());
    }

    public function test_acting_within_24_hours_after_the_due_day_is_still_on_time(): void
    {
        $customer = $this->customer();
        $this->logFollowUp($customer, Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($customer, null, createdAt: Carbon::parse('2026-09-11 23:30'));

        $this->assertSame(FollowUpOutcome::OnTime, $this->outcomeOfFirstRow());
    }

    public function test_acting_after_the_grace_period_is_late(): void
    {
        $customer = $this->customer();
        $this->logFollowUp($customer, Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($customer, null, createdAt: Carbon::parse('2026-09-12 00:30'));

        $this->assertSame(FollowUpOutcome::Late, $this->outcomeOfFirstRow());
    }

    public function test_never_acting_after_the_grace_period_is_missed(): void
    {
        $this->logFollowUp($this->customer(), Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));

        $this->assertSame(FollowUpOutcome::Missed, $this->outcomeOfFirstRow());
    }

    public function test_an_unanswered_follow_up_inside_the_grace_period_is_pending_and_a_future_one_upcoming(): void
    {
        $this->logFollowUp($this->customer(), Carbon::parse('2026-09-15 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($this->customer(), Carbon::parse('2026-09-18 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));

        $outcomes = $this->classifyAll()->pluck('monitor_outcome')->all();

        $this->assertSame([FollowUpOutcome::Pending, FollowUpOutcome::Upcoming], $outcomes);
    }

    public function test_a_date_moved_before_its_day_arrived_never_fell_due(): void
    {
        $customer = $this->customer();
        $this->logFollowUp($customer, Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($customer, Carbon::parse('2026-09-14 10:00'), createdAt: Carbon::parse('2026-09-09 09:00'));

        $classified = $this->classifyAll();

        $this->assertCount(1, $classified);
        $this->assertSame('2026-09-14', $classified->first()->next_follow_up_date->toDateString());
        $this->assertSame(FollowUpOutcome::Missed, $classified->first()->monitor_outcome);
    }

    public function test_the_summary_gives_the_on_time_rate_and_average_lateness(): void
    {
        $onTime = $this->customer();
        $this->logFollowUp($onTime, Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($onTime, null, createdAt: Carbon::parse('2026-09-10 11:00'));

        $late = $this->customer();
        $this->logFollowUp($late, Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($late, null, createdAt: Carbon::parse('2026-09-13 10:00'));

        $this->logFollowUp($this->customer(), Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($this->customer(), Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));

        $summary = $this->monitor->summarize($this->classifyAll());

        $this->assertSame(1, $summary['on_time']);
        $this->assertSame(1, $summary['late']);
        $this->assertSame(2, $summary['missed']);
        $this->assertSame(4, $summary['judged']);
        $this->assertSame(25.0, $summary['on_time_rate']);
        $this->assertSame(72.0, $summary['average_late_hours']);
    }

    /*
    |--------------------------------------------------------------------------
    | Scope, backlog, load
    |--------------------------------------------------------------------------
    */

    public function test_a_team_leader_monitors_only_their_own_branch(): void
    {
        $this->logFollowUp($this->customer(), Carbon::parse('2026-09-10 10:00'));
        $this->logFollowUp($this->customer($this->otherCaller), Carbon::parse('2026-09-10 10:00'), employee: $this->otherCaller);

        $teamLeaderUser = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $admin = User::factory()->create()->assignRole('Admin');

        $this->assertSame([$this->caller->id], $this->monitor->scopedQueryFor($teamLeaderUser)->pluck('employee_id')->all());
        $this->assertCount(2, $this->monitor->scopedQueryFor($admin)->get());
    }

    public function test_the_open_backlog_is_bucketed_by_age(): void
    {
        $this->logFollowUp($this->customer(), now()->subHours(3));
        $this->logFollowUp($this->customer(), now()->subDays(3));
        $this->logFollowUp($this->customer(), now()->subDays(12));
        $this->logFollowUp($this->customer(), now()->subDays(45));
        $this->logFollowUp($this->customer(), now()->addDay());

        $this->assertSame(
            ['Under 1 day' => 1, '1–7 days' => 1, '8–30 days' => 1, 'Over 30 days' => 1],
            $this->monitor->backlogAges(FollowUp::query()),
        );
    }

    public function test_a_caller_booked_past_the_daily_capacity_is_overloaded(): void
    {
        foreach (range(1, FollowUpMonitorService::DAILY_CAPACITY + 1) as $ignored) {
            $this->logFollowUp($this->customer(), Carbon::parse('2026-09-17 10:00'));
        }

        $load = $this->monitor->openLoad(FollowUp::query(), today(), today()->addDays(6)->endOfDay());

        $this->assertSame(
            [['employee_id' => $this->caller->id, 'date' => '2026-09-17', 'count' => FollowUpMonitorService::DAILY_CAPACITY + 1]],
            $this->monitor->overloadedDays($load),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Spreading a backlog
    |--------------------------------------------------------------------------
    */

    public function test_spreading_fills_each_working_day_up_to_capacity_and_skips_sunday(): void
    {
        // Saturday noon: 10:00 has passed, so today starts from the next slot.
        $this->travelTo(Carbon::parse('2026-09-19 12:00:00'));

        $missed = collect(range(1, 30))->map(fn () => $this->logFollowUp($this->customer(), Carbon::parse('2026-09-10 10:00')));

        $supervisor = User::factory()->create(['employee_id' => $this->teamLeader->id]);

        $moved = $this->monitor->spread($missed, $supervisor, today(), 2, '10:00', 'Backlog cleared');

        $this->assertSame(30, $moved);

        $current = FollowUp::query()->latestPerSubject()->get();

        $this->assertSame(
            ['2026-09-19' => 25, '2026-09-21' => 5],
            $current->countBy(fn (FollowUp $followUp) => $followUp->next_follow_up_date->toDateString())->sortKeys()->all(),
        );
        $this->assertSame('12:00', $current->min('next_follow_up_date')->format('H:i'));
        $this->assertSame(
            '10:00',
            $current->filter(fn (FollowUp $followUp) => $followUp->next_follow_up_date->isMonday())->min('next_follow_up_date')->format('H:i'),
        );
        $this->assertTrue($current->every(fn (FollowUp $followUp) => $followUp->employee_id === $this->caller->id), 'The caller stays the owner when a supervisor spreads their backlog.');
        $this->assertTrue($current->every(fn (FollowUp $followUp) => $followUp->remarks === 'Backlog cleared'));
        $this->assertSame(60, FollowUp::count(), 'Spreading logs new rows; the missed rows stay in the log.');
    }

    /*
    |--------------------------------------------------------------------------
    | Calendar
    |--------------------------------------------------------------------------
    */

    public function test_the_calendar_splits_a_past_day_into_outcomes(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));

        $kept = $this->customer();
        $this->logFollowUp($kept, Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($kept, null, createdAt: Carbon::parse('2026-09-10 11:00'));
        $this->logFollowUp($this->customer(), Carbon::parse('2026-09-10 15:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($this->customer(), Carbon::parse('2026-09-17 15:00'));

        $events = collect((new CustomerFollowUpCalendarWidget)->fetchEvents([
            'start' => '2026-09-01',
            'end' => '2026-09-30',
        ]))->keyBy('extendedProps.date');

        $this->assertSame(1, $events['2026-09-10']['extendedProps']['onTime']);
        $this->assertSame(1, $events['2026-09-10']['extendedProps']['missed']);
        $this->assertSame(0, $events['2026-09-10']['extendedProps']['open']);
        $this->assertSame(1, $events['2026-09-17']['extendedProps']['open']);
    }

    public function test_the_calendar_day_panel_spreads_the_days_missed_follow_ups(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));

        $this->logFollowUp($this->customer(), Carbon::parse('2026-09-10 15:00'), createdAt: Carbon::parse('2026-09-08 09:00'));

        Livewire::test(CustomerFollowUpCalendarWidget::class)
            ->set('selectedDate', '2026-09-10')
            ->assertSee('1 missed follow-up')
            ->callAction('spreadMissed', [
                'start_date' => '2026-09-17',
                'days' => 1,
                'start_time' => '11:00',
                'remarks' => 'Picked up again',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame('2026-09-17 11:00', FollowUp::query()->latest('id')->first()->next_follow_up_date->format('Y-m-d H:i'));
    }

    public function test_every_users_dashboard_calendar_shows_their_own_lead_and_customer_outcomes(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));

        Lead::create([
            'employee_id' => $this->caller->id,
            'customer_name' => 'Raw Lead',
            'mobile_no' => '9876500001',
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'call later',
            'next_follow_up_date' => Carbon::parse('2026-09-10 11:00'),
        ]);
        $this->logFollowUp($this->customer(), Carbon::parse('2026-09-10 15:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($this->customer($this->otherCaller), Carbon::parse('2026-09-10 15:00'), employee: $this->otherCaller);

        $events = collect((new DashboardFollowUpCalendarWidget)->fetchEvents([
            'start' => '2026-09-01',
            'end' => '2026-09-30',
        ]))->keyBy('extendedProps.date');

        $this->assertSame(2, $events['2026-09-10']['extendedProps']['missed'], "The caller's lead and customer follow-up, not another caller's.");
        $this->assertSame(2, $events['2026-09-10']['extendedProps']['count']);
    }

    public function test_a_caller_drops_the_days_missed_follow_ups_from_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));

        $customer = $this->customer();
        $this->logFollowUp($customer, Carbon::parse('2026-09-10 15:00'), createdAt: Carbon::parse('2026-09-08 09:00'));

        Livewire::test(DashboardFollowUpCalendarWidget::class)
            ->set('selectedDate', '2026-09-10')
            ->assertSee('1 missed follow-up')
            ->assertSee('Missed')
            ->callAction('dropMissed', ['remarks' => 'Number not reachable'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame('Dropped', FollowUp::query()->where('customer_id', $customer->id)->latest('id')->first()->status);
    }

    public function test_a_converted_leads_card_opens_the_customer_it_became_not_a_404(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));

        $customer = $this->customer();
        $lead = Lead::create([
            'employee_id' => $this->caller->id,
            'customer_name' => 'Converted Lead',
            'mobile_no' => '9876500002',
            'follow_up_type' => 'Call',
            'status' => 'Interested',
            'remarks' => 'converted',
            'next_follow_up_date' => Carbon::parse('2026-09-10 11:00'),
        ]);
        $lead->forceFill(['is_converted' => true, 'converted_customer_id' => $customer->id])->saveQuietly();

        Livewire::test(DashboardFollowUpCalendarWidget::class)
            ->set('selectedDate', '2026-09-10')
            ->assertSee('Converted Lead')
            ->assertSee(CustomerResource::getUrl('view', ['record' => $customer->id]), escape: false)
            ->assertDontSee(LeadResource::getUrl('edit', ['record' => $lead->id]), escape: false)
            ->assertSee('markSelectedDay', escape: false);
    }

    public function test_an_open_leads_card_still_opens_the_lead(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));

        $lead = Lead::create([
            'employee_id' => $this->caller->id,
            'customer_name' => 'Open Lead',
            'mobile_no' => '9876500003',
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'call later',
            'next_follow_up_date' => Carbon::parse('2026-09-10 11:00'),
        ]);

        Livewire::test(DashboardFollowUpCalendarWidget::class)
            ->set('selectedDate', '2026-09-10')
            ->assertSee(LeadResource::getUrl('edit', ['record' => $lead->id]), escape: false);
    }

    /*
    |--------------------------------------------------------------------------
    | Monitor page
    |--------------------------------------------------------------------------
    */

    public function test_supervisors_and_admins_can_open_the_monitor_but_callers_cannot(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('Admin'));
        $this->assertTrue(FollowUpMonitor::canAccess());

        $this->actingAs(User::factory()->create(['employee_id' => $this->teamLeader->id]));
        $this->assertTrue(FollowUpMonitor::canAccess());

        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));
        $this->assertFalse(FollowUpMonitor::canAccess());
        Livewire::test(FollowUpMonitor::class)->assertForbidden();
    }

    public function test_the_monitor_is_in_the_sidebar_for_an_admin(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('Admin'));

        $this->followingRedirects()
            ->get('/admin')
            ->assertSee(FollowUpMonitor::getUrl());
    }

    public function test_the_monitor_shows_the_team_and_drops_a_callers_missed_follow_ups(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->teamLeader->id]));

        $customer = $this->customer();
        $this->logFollowUp($customer, Carbon::parse('2026-09-10 10:00'), createdAt: Carbon::parse('2026-09-08 09:00'));
        $this->logFollowUp($this->customer($this->otherCaller), Carbon::parse('2026-09-10 10:00'), employee: $this->otherCaller);

        Livewire::test(FollowUpMonitor::class)
            ->assertOk()
            ->assertSee($this->caller->emp_name)
            ->assertDontSee($this->otherCaller->emp_name)
            ->call('focusEmployee', $this->caller->id)
            ->assertSee('Missed follow-ups — '.$this->caller->emp_name)
            ->callAction('dropFocused', ['remarks' => 'Customer unreachable'])
            ->assertHasNoActionErrors();

        $this->assertSame('Dropped', FollowUp::query()->where('customer_id', $customer->id)->latest('id')->first()->status);
        $this->assertSame(1, FollowUp::query()->where('employee_id', $this->otherCaller->id)->count(), 'Another branch is left alone.');
    }

    public function test_a_team_leader_cannot_focus_someone_outside_their_branch(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->teamLeader->id]));

        Livewire::test(FollowUpMonitor::class)
            ->call('focusEmployee', $this->otherCaller->id)
            ->assertSet('focusEmployeeId', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function outcomeOfFirstRow(): ?FollowUpOutcome
    {
        return $this->classifyAll()->first()?->monitor_outcome;
    }

    /**
     * @return Collection<int, FollowUp>
     */
    private function classifyAll(): Collection
    {
        return $this->monitor->classify(FollowUp::query()->orderBy('id'), Carbon::parse('2026-08-01'), Carbon::parse('2026-10-31'));
    }

    private function customer(?Employee $owner = null): Customer
    {
        $owner ??= $this->caller;

        return Customer::factory()->create(['assign_to' => $owner->id, 'employee_id' => $owner->id]);
    }

    private function logFollowUp(Customer $customer, ?Carbon $nextDate, ?Carbon $createdAt = null, ?Employee $employee = null): FollowUp
    {
        $followUp = FollowUp::create([
            'customer_id' => $customer->id,
            'employee_id' => ($employee ?? $this->caller)->id,
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'logged',
            'next_follow_up_date' => $nextDate,
        ]);

        if ($createdAt) {
            $followUp->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $followUp;
    }
}
