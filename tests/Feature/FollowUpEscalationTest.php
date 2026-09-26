<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\FollowUpEscalationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Long-overdue follow-ups escalate up the reporting line (48 hours to the
 * boss, 7 days to the boss above), and supervisors get a morning summary.
 */
class FollowUpEscalationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller;

    private User $managerUser;

    private User $teamLeaderUser;

    private User $callerUser;

    private FollowUpEscalationService $escalations;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->travelTo(Carbon::parse('2026-09-16 12:00:00'));

        $this->manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'exit_status' => 'no', 'emp_name' => 'Meera Manager']);
        $this->teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->manager->id,
            'exit_status' => 'no',
            'emp_name' => 'Tarun Leader',
        ]);
        $this->caller = $this->callerUnder($this->teamLeader, 'Ravi Caller');

        $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id]);
        $this->teamLeaderUser = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $this->callerUser = User::factory()->create(['employee_id' => $this->caller->id]);

        $this->escalations = app(FollowUpEscalationService::class);
    }

    public function test_a_follow_up_open_48_hours_past_its_time_goes_to_the_callers_boss_once(): void
    {
        $followUp = $this->logFollowUp(now()->subHours(49));

        $this->assertSame(1, $this->escalations->escalate());
        $this->assertSame(0, $this->escalations->escalate());

        $notification = $this->teamLeaderUser->notifications()->sole();
        $this->assertStringContainsString('Escalation: 1 follow-up overdue 48 hours+', $notification->data['title']);
        $this->assertStringContainsString('Ravi Caller 1', $notification->data['body']);
        $this->assertSame(0, $this->managerUser->notifications()->count());
        $this->assertSame(1, $followUp->fresh()->escalation_level);
    }

    public function test_a_follow_up_not_yet_48_hours_late_is_not_escalated(): void
    {
        $this->logFollowUp(now()->subHours(30));

        $this->assertSame(0, $this->escalations->escalate());
        $this->assertSame(0, $this->teamLeaderUser->notifications()->count());
    }

    public function test_a_week_overdue_reaches_both_the_boss_and_the_boss_above(): void
    {
        $followUp = $this->logFollowUp(now()->subDays(8));

        $this->escalations->escalate();

        $this->assertSame(1, $this->teamLeaderUser->notifications()->count());
        $this->assertStringContainsString('overdue 7 days+', $this->managerUser->notifications()->sole()->data['title']);
        $this->assertSame(2, $followUp->fresh()->escalation_level);
    }

    public function test_crossing_the_week_later_only_adds_the_boss_above(): void
    {
        $this->logFollowUp(now()->subDays(3));
        $this->escalations->escalate();

        $this->travel(5)->days();
        $this->escalations->escalate();

        $this->assertSame(1, $this->teamLeaderUser->notifications()->count());
        $this->assertSame(1, $this->managerUser->notifications()->count());
    }

    public function test_an_exited_team_leader_is_skipped(): void
    {
        $this->teamLeader->update(['exit_status' => 'yes']);

        $this->logFollowUp(now()->subHours(50));
        $this->escalations->escalate();

        $this->assertSame(0, $this->teamLeaderUser->notifications()->count());
        $this->assertStringContainsString('overdue 48 hours+', $this->managerUser->notifications()->sole()->data['title']);
    }

    public function test_a_follow_up_acted_on_is_not_escalated(): void
    {
        $customer = $this->customer($this->caller);
        $this->logFollowUp(now()->subDays(3), $customer);
        $this->logFollowUp(now()->addDay(), $customer);

        $this->assertSame(0, $this->escalations->escalate());
    }

    public function test_one_grouped_notification_per_supervisor_names_each_caller(): void
    {
        $secondCaller = $this->callerUnder($this->teamLeader, 'Asha Caller');

        $this->logFollowUp(now()->subDays(3));
        $this->logFollowUp(now()->subDays(3));
        $this->logFollowUp(now()->subDays(3), employee: $secondCaller);

        $this->escalations->escalate();

        $notification = $this->teamLeaderUser->notifications()->sole();
        $this->assertStringContainsString('3 follow-ups', $notification->data['title']);
        $this->assertStringContainsString('Ravi Caller 2, Asha Caller 1', $notification->data['body']);
    }

    public function test_supervisors_get_one_morning_summary_and_callers_none(): void
    {
        $this->logFollowUp(now()->setTime(15, 0));
        $this->logFollowUp(Carbon::parse('2026-09-14 11:00'));

        $this->assertSame(2, $this->escalations->sendMorningDigests());
        $this->assertSame(0, $this->escalations->sendMorningDigests(), 'Sent at most once a day.');

        $body = $this->teamLeaderUser->notifications()->sole()->data['body'];
        $this->assertStringContainsString('1 due today', $body);
        $this->assertStringContainsString('1 newly missed (Ravi Caller 1)', $body);
        $this->assertStringContainsString('1 open overdue', $body);
        $this->assertSame(1, $this->managerUser->notifications()->count());
        $this->assertSame(0, $this->callerUser->notifications()->count());
    }

    public function test_a_supervisor_with_nothing_to_report_gets_no_summary(): void
    {
        $this->assertSame(0, $this->escalations->sendMorningDigests());
    }

    public function test_the_commands_run(): void
    {
        $this->logFollowUp(now()->subHours(49));

        $this->artisan('follow-ups:escalate')->assertSuccessful();
        $this->artisan('follow-ups:morning-digest')->assertSuccessful();

        $this->assertSame(2, $this->teamLeaderUser->notifications()->count());
    }

    private function callerUnder(Employee $teamLeader, string $name): Employee
    {
        return Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $teamLeader->manager_id,
            'exit_status' => 'no',
            'emp_name' => $name,
        ]);
    }

    private function customer(Employee $owner): Customer
    {
        return Customer::factory()->create(['assign_to' => $owner->id, 'employee_id' => $owner->id]);
    }

    private function logFollowUp(Carbon $nextDate, ?Customer $customer = null, ?Employee $employee = null): FollowUp
    {
        $employee ??= $this->caller;

        return FollowUp::create([
            'customer_id' => ($customer ?? $this->customer($employee))->id,
            'employee_id' => $employee->id,
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'logged',
            'next_follow_up_date' => $nextDate,
        ]);
    }
}
