<?php

namespace Tests\Feature;

use App\Enums\InactivityRequestStatus;
use App\Filament\Resources\EmployeeInactivityRequests\EmployeeInactivityRequestResource;
use App\Filament\Resources\EmployeeInactivityRequests\Pages\CreateEmployeeInactivityRequest;
use App\Filament\Resources\EmployeeInactivityRequests\Pages\ListEmployeeInactivityRequests;
use App\Models\Employee;
use App\Models\EmployeeInactivityRequest;
use App\Models\User;
use App\Services\MonthlyTargetGate;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The inactivity ticket is the alternative to inventing a monthly target
 * for somebody who has stopped turning up: a Manager raises it, the
 * target is skipped from that moment, and the Admin line decides
 * afterwards whether the employee really comes off the rolls.
 */
class EmployeeInactivityRequestTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $caller;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Manager', 'Team Leader', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'exit_status' => 'no',
        ]);

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->manager->id,
            'exit_status' => 'no',
        ]);

        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $this->manager->id,
            'exit_status' => 'no',
        ]);

        foreach ([[$this->manager, 'Manager'], [$teamLeader, 'Team Leader'], [$this->caller, 'Caller']] as [$employee, $role]) {
            User::factory()->create(['employee_id' => $employee->id])->assignRole($role);
        }
    }

    public function test_a_manager_raises_a_ticket_for_their_own_caller(): void
    {
        $this->actingAs($this->managerUser());

        Livewire::test(CreateEmployeeInactivityRequest::class)
            ->fillForm([
                'employee_id' => $this->caller->id,
                'month' => today()->startOfMonth()->toDateString(),
                'reason' => 'Absent since 1 Sep, no contact.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = EmployeeInactivityRequest::query()->firstOrFail();

        $this->assertSame($this->caller->id, $ticket->employee_id);
        $this->assertSame(InactivityRequestStatus::Pending, $ticket->status);
        $this->assertSame($this->managerUser()->getKey(), $ticket->requested_by);
    }

    public function test_a_second_open_ticket_for_the_same_month_is_refused(): void
    {
        $this->ticket();

        $this->actingAs($this->managerUser());

        Livewire::test(CreateEmployeeInactivityRequest::class)
            ->fillForm([
                'employee_id' => $this->caller->id,
                'month' => today()->startOfMonth()->toDateString(),
                'reason' => 'Absent since 1 Sep, no contact.',
            ])
            ->call('create')
            ->assertHasFormErrors(['employee_id']);
    }

    public function test_a_caller_cannot_reach_the_ticket_screen_at_all(): void
    {
        $this->actingAs($this->userFor($this->caller));

        $this->assertFalse(EmployeeInactivityRequestResource::canAccess());
    }

    public function test_only_the_admin_line_reviews_a_ticket(): void
    {
        $this->actingAs($this->managerUser());
        $this->assertFalse(EmployeeInactivityRequestResource::isReviewer());

        $this->actingAs($this->adminUser());
        $this->assertTrue(EmployeeInactivityRequestResource::isReviewer());
    }

    public function test_approving_a_ticket_marks_the_employee_inactive(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->adminUser());

        Livewire::test(ListEmployeeInactivityRequests::class)
            ->callAction(TestAction::make('approve')->table($ticket), ['review_note' => 'Confirmed with HR.']);

        $ticket->refresh();

        $this->assertSame(InactivityRequestStatus::Approved, $ticket->status);
        $this->assertNotNull($ticket->reviewed_at);
        $this->assertSame('yes', $this->caller->fresh()->exit_status);
    }

    public function test_rejecting_a_ticket_leaves_the_employee_on_the_rolls(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->adminUser());

        Livewire::test(ListEmployeeInactivityRequests::class)
            ->callAction(TestAction::make('reject')->table($ticket), ['review_note' => 'They are back from leave.']);

        $ticket->refresh();

        $this->assertSame(InactivityRequestStatus::Rejected, $ticket->status);
        $this->assertSame('no', $this->caller->fresh()->exit_status);

        // And their target is demanded again.
        app(MonthlyTargetGate::class)->forget();
        $this->assertFalse(app(MonthlyTargetGate::class)->isSkipped($this->caller->id));
    }

    public function test_a_manager_only_sees_tickets_from_their_own_tree(): void
    {
        $mine = $this->ticket();

        $outsider = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'exit_status' => 'no',
        ]);

        $theirs = EmployeeInactivityRequest::create([
            'employee_id' => $outsider->id,
            'month' => today()->startOfMonth()->toDateString(),
            'reason' => 'Somebody else\'s problem.',
            'status' => InactivityRequestStatus::Pending,
        ]);

        $this->actingAs($this->managerUser());

        Livewire::test(ListEmployeeInactivityRequests::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function ticket(): EmployeeInactivityRequest
    {
        $ticket = EmployeeInactivityRequest::create([
            'employee_id' => $this->caller->id,
            'month' => today()->startOfMonth()->toDateString(),
            'requested_by' => $this->managerUser()->getKey(),
            'reason' => 'Absent since 1 Sep, no contact.',
            'status' => InactivityRequestStatus::Pending,
        ]);

        app(MonthlyTargetGate::class)->forget();

        return $ticket;
    }

    private function managerUser(): User
    {
        return $this->userFor($this->manager);
    }

    private function userFor(Employee $employee): User
    {
        app(MonthlyTargetGate::class)->forget();

        return User::query()->where('employee_id', $employee->id)->firstOrFail();
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        app(MonthlyTargetGate::class)->forget();

        return $user;
    }
}
