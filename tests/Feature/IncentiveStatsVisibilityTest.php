<?php

namespace Tests\Feature;

use App\Filament\Widgets\IncentiveStats;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IncentiveStatsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function userFor(int $designation): User
    {
        $employee = Employee::factory()->create(['designation' => $designation]);

        return User::factory()->create(['employee_id' => $employee->id]);
    }

    public function test_caller_can_view(): void
    {
        $this->actingAs($this->userFor(Employee::DESIGNATION_CALLER));

        $this->assertTrue(IncentiveStats::canView());
    }

    public function test_team_leader_can_view(): void
    {
        $this->actingAs($this->userFor(Employee::DESIGNATION_TEAM_LEADER));

        $this->assertTrue(IncentiveStats::canView());
    }

    public function test_manager_can_view(): void
    {
        $this->actingAs($this->userFor(Employee::DESIGNATION_MANAGER));

        $this->assertTrue(IncentiveStats::canView());
    }

    public function test_cluster_manager_can_view(): void
    {
        $this->actingAs($this->userFor(Employee::DESIGNATION_CLUSTER));

        $this->assertTrue(IncentiveStats::canView());
    }

    public function test_admin_can_view_even_without_a_linked_employee(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin);

        $this->assertTrue(IncentiveStats::canView());
    }

    public function test_a_user_with_no_employee_and_no_admin_role_cannot_view(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(IncentiveStats::canView());
    }

    public function test_a_guest_cannot_view(): void
    {
        $this->assertFalse(IncentiveStats::canView());
    }
}
