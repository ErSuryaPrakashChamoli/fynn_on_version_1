<?php

namespace Tests\Feature;

use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Proves the "Eligible Callers" and "PPP" columns added to the Teams
 * listing (ListTeams) and the per-record Team View listing (ViewTeam) show
 * the correct calculator-derived values for every non-Caller position, and
 * are blank for Caller rows (PPP/eligible-caller-count is not a concept
 * that applies to an individual Caller).
 */
class TeamsListEligibleCallersPppColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $cluster;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller1;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->cluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
        ]);

        $this->manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $this->cluster->id,
        ]);

        $this->teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
        ]);

        $this->caller1 = $this->caller(3000000);
        $this->caller(2000000);
        $this->caller(1000000);

        // Team achievement: 3,000,000 + 2,000,000 + 1,000,000 = 6,000,000
        // across 3 eligible callers -> PPP = 2,000,000 for the TL, Manager,
        // and Cluster Manager alike (same single-branch hierarchy).
    }

    private function caller(float $loanAmount): Employee
    {
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => $loanAmount,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_date' => now(),
        ]);

        return $caller;
    }

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_teams_list_shows_eligible_callers_and_ppp_for_cluster_manager(): void
    {
        $this->actingAsAdmin();

        // Admin's Teams list shows Cluster Managers at the top level.
        Livewire::test(ListTeams::class)
            ->assertTableColumnStateSet('eligible_callers', 3, $this->cluster)
            ->assertTableColumnStateSet('ppp', 2000000.0, $this->cluster);
    }

    public function test_view_team_shows_eligible_callers_and_ppp_for_team_leader_row(): void
    {
        $this->actingAsAdmin();

        // Viewing the Manager's team lists its Team Leaders.
        Livewire::test(ViewTeam::class, ['record' => $this->manager])
            ->assertTableColumnStateSet('eligible_callers', 3, $this->teamLeader)
            ->assertTableColumnStateSet('ppp', 2000000.0, $this->teamLeader);
    }

    public function test_view_team_leaves_eligible_callers_and_ppp_blank_for_caller_rows(): void
    {
        $this->actingAsAdmin();

        // Viewing the Team Leader's team lists its Callers -> both columns
        // must be null (rendered as "-") for a Caller row.
        Livewire::test(ViewTeam::class, ['record' => $this->teamLeader])
            ->assertTableColumnStateSet('eligible_callers', null, $this->caller1)
            ->assertTableColumnStateSet('ppp', null, $this->caller1);
    }
}
