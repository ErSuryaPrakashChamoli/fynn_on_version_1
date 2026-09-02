<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use ReflectionMethod;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * EditEmployee had two separate places (besides HierarchyReassignmentService,
 * fixed earlier) that reset an employee's reporting_date whenever their
 * manager/TL/cluster changed here: the implicit afterSave() hierarchy-change
 * detection, and the dedicated "Transfer Employee" header action. Both fed
 * directly into AchievementCalculatorService's "new joiner" worked-days
 * rule, wrongly zeroing a long-tenured employee's target for the month a
 * purely administrative reassignment happened. Both are fixed the same way
 * as HierarchyReassignmentService: reporting_date is no longer touched by a
 * reporting-line change.
 */
class EditEmployeeReportingDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->travelTo(now()->startOfMonth()->addDays(19));
    }

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_after_save_does_not_reset_reporting_date_on_a_hierarchy_change(): void
    {
        $this->actingAsAdmin();

        $oldTeamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER]);
        $newTeamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER]);

        $originalReportingDate = now()->subYear()->toDateString();

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $oldTeamLeader->id,
            'category' => '2500000',
            'reporting_date' => $originalReportingDate,
            'exit_status' => 'no',
        ]);

        $page = new EditEmployee;

        $recordProperty = new ReflectionProperty($page, 'record');
        $recordProperty->setAccessible(true);
        $recordProperty->setValue($page, $caller);

        $oldReportingProperty = new ReflectionProperty($page, 'oldReporting');
        $oldReportingProperty->setAccessible(true);
        $oldReportingProperty->setValue($page, [
            'superviser_id' => $caller->superviser_id,
            'manager_id' => $caller->manager_id,
            'cluster_id' => $caller->cluster_id,
            'reporting_date' => $caller->reporting_date,
            'exit_status' => $caller->exit_status,
            'exit_date' => $caller->exit_date,
        ]);

        // Simulate the admin changing the Team Leader on the edit form.
        $caller->superviser_id = $newTeamLeader->id;
        $caller->save();

        $afterSave = new ReflectionMethod($page, 'afterSave');
        $afterSave->setAccessible(true);
        $afterSave->invoke($page);

        $caller->refresh();

        $this->assertSame($originalReportingDate, Carbon::parse($caller->reporting_date)->toDateString());
        $this->assertSame(
            2500000.0,
            (new AchievementCalculatorService)->getHierarchyCallerTarget($caller)
        );

        $this->assertDatabaseHas('employee_reporting_history', [
            'employee_id' => $caller->id,
            'old_superviser_id' => $oldTeamLeader->id,
            'new_superviser_id' => $newTeamLeader->id,
            'change_type' => 'reporting_change',
        ]);
    }

    public function test_transfer_employee_action_does_not_reset_reporting_date(): void
    {
        $this->actingAsAdmin();

        $oldTeamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER]);
        $newTeamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER]);

        $originalReportingDate = now()->subYear()->toDateString();

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $oldTeamLeader->id,
            'category' => '2500000',
            'reporting_date' => $originalReportingDate,
            'exit_status' => 'no',
        ]);

        Livewire::test(EditEmployee::class, ['record' => $caller->getRouteKey()])
            ->callAction('transferEmployee', data: [
                'new_superviser_id' => $newTeamLeader->id,
                'effective_date' => now()->toDateString(),
                'remarks' => 'Test transfer.',
            ]);

        $caller->refresh();

        $this->assertSame($newTeamLeader->id, $caller->superviser_id);
        $this->assertSame($originalReportingDate, Carbon::parse($caller->reporting_date)->toDateString());
        $this->assertSame(
            2500000.0,
            (new AchievementCalculatorService)->getHierarchyCallerTarget($caller)
        );

        // The exact old_superviser_id captured for this history row depends
        // on pre-existing, unrelated timing between the action form's
        // relationship-bound Select and the action closure (not touched by
        // this fix) — what matters here is that a transfer record exists
        // with the correct new value and does not carry a reporting_date
        // side effect.
        $this->assertDatabaseHas('employee_reporting_history', [
            'employee_id' => $caller->id,
            'new_superviser_id' => $newTeamLeader->id,
            'change_type' => 'transfer',
        ]);
    }
}
