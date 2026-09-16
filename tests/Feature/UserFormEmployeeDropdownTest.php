<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeOptions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Employee dropdown on the user form names the person, their employee ID
 * and whoever they report to, so a login can be attached without opening the
 * employee record to check where they sit in the hierarchy.
 */
class UserFormEmployeeDropdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_label_carries_the_name_employee_id_and_reporting_manager(): void
    {
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'emp_name' => 'Nitin Thakur',
            'emp_id' => 'FYN-0100',
        ]);

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'emp_name' => 'Asha Rao',
            'emp_id' => 'FYN-0142',
            'superviser_id' => $teamLeader->id,
        ]);

        $this->assertSame(
            'Asha Rao (FYN-0142) - Reports to: Nitin Thakur (Team Leader)',
            EmployeeOptions::labelWithReportingLine($caller),
        );
    }

    public function test_a_skipped_level_names_the_boss_the_branch_actually_hangs_off(): void
    {
        $cluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
            'emp_name' => 'Kanak Kumar',
            'emp_id' => 'FYN-0400',
        ]);

        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'emp_name' => 'Rohit Sharma',
            'emp_id' => 'FYN-0200',
            'cluster_id' => $cluster->id,
        ]);

        // No Team Leader in between: the caller hangs off the Manager.
        $skipLevelCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'emp_name' => 'Vikram Singh',
            'emp_id' => 'FYN-0300',
            'superviser_id' => null,
            'manager_id' => $manager->id,
        ]);

        $this->assertSame(
            'Vikram Singh (FYN-0300) - Reports to: Rohit Sharma (Manager)',
            EmployeeOptions::labelWithReportingLine($skipLevelCaller),
        );

        $this->assertSame(
            'Rohit Sharma (FYN-0200) - Reports to: Kanak Kumar (Cluster Manager)',
            EmployeeOptions::labelWithReportingLine($manager),
        );
    }

    public function test_nobody_above_them_reads_not_assigned(): void
    {
        $businessHead = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_BUSINESS_HEAD,
            'emp_name' => 'Prabhat Tyagi',
            'emp_id' => 'FYN-0500',
        ]);

        $supportSeat = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_OTHER_BANK_SUPPORT,
            'emp_name' => 'Raja Kush',
            'emp_id' => 'FA000009',
        ]);

        $this->assertSame('Prabhat Tyagi (FYN-0500) - Reports to: Not assigned', EmployeeOptions::labelWithReportingLine($businessHead));
        $this->assertSame('Raja Kush (FA000009) - Reports to: Not assigned', EmployeeOptions::labelWithReportingLine($supportSeat));
    }

    public function test_a_column_pointing_at_the_wrong_level_is_not_named_as_the_boss(): void
    {
        // Live data has callers with a Manager sitting in superviser_id. That
        // column only holds Team Leaders, so ReportingTree ignores it and the
        // broken reporting line shows as unassigned rather than as a wrong name.
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'emp_name' => 'Rohit Sharma',
            'emp_id' => 'FYN-0200',
        ]);

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'emp_name' => 'Vikram Singh',
            'emp_id' => 'FYN-0300',
            'superviser_id' => $manager->id,
        ]);

        $this->assertSame('Vikram Singh (FYN-0300) - Reports to: Not assigned', EmployeeOptions::labelWithReportingLine($caller));
    }

    public function test_the_create_user_dropdown_offers_those_labels(): void
    {
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'emp_name' => 'Nitin Thakur',
            'emp_id' => 'FYN-0100',
        ]);

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'emp_name' => 'Asha Rao',
            'emp_id' => 'FYN-0142',
            'superviser_id' => $teamLeader->id,
        ]);

        $labels = $this->employeeSelectLabels();

        $this->assertSame('Asha Rao (FYN-0142) - Reports to: Nitin Thakur (Team Leader)', $labels[$caller->id] ?? null);
        $this->assertSame('Nitin Thakur (FYN-0100) - Reports to: Not assigned', $labels[$teamLeader->id] ?? null);
    }

    /**
     * @return array<int, string>
     */
    private function employeeSelectLabels(): array
    {
        $page = Livewire::test(CreateUser::class)->assertOk()->instance();

        foreach ($page->getSchema('form')->getFlatComponents() as $component) {
            if ($component instanceof Select && $component->getName() === 'employee_id') {
                return $component->getOptions();
            }
        }

        $this->fail('The user form has no employee_id select.');
    }
}
