<?php

namespace Tests\Feature;

use App\Filament\Widgets\ManagerPPPStats;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\Performance\EmployeeFigureBreakdownService;
use App\Support\EmployeeLookupPeriod;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementCalculatorManagerIncentiveTest extends TestCase
{
    use RefreshDatabase;

    private function manager(int $callerCount, float $loanAmountPerCaller): Employee
    {
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
        ]);

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'exit_status' => 'no',
        ]);

        $this->callers($callerCount, $loanAmountPerCaller, [
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
        ]);

        return $manager;
    }

    /**
     * Cluster Manager ── Manager ── Team Leader ── $callerCount callers.
     */
    private function cluster(int $callerCount, float $loanAmountPerCaller): Employee
    {
        $cluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
        ]);

        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $cluster->id,
            'exit_status' => 'no',
        ]);

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'cluster_id' => $cluster->id,
            'exit_status' => 'no',
        ]);

        $this->callers($callerCount, $loanAmountPerCaller, [
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'cluster_id' => $cluster->id,
        ]);

        return $cluster;
    }

    /**
     * @param  array<string, int>  $reportingColumns
     */
    private function callers(int $callerCount, float $loanAmountPerCaller, array $reportingColumns): void
    {
        Employee::factory()->count($callerCount)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '2500000',
            'reporting_date' => null,
            ...$reportingColumns,
        ])->each(function (Employee $caller) use ($loanAmountPerCaller) {
            Customer::factory()->create([
                'employee_id' => $caller->id,
                'sanctioned_loan_amount' => $loanAmountPerCaller,
                'cashback' => 0,
                'subvention' => 0,
                'docking' => '0',
                'disbursal_date' => now(),
            ]);
        });
    }

    public function test_manager_incentive_is_zero_when_ppp_is_below_the_floor_even_though_team_achievement_exceeds_every_flat_slab(): void
    {
        // 15 callers, ~961,528 achievement each -> team total ~14,422,923,
        // which is above the highest flat caller slab (11,000,000 -> 70,000)
        // but PPP (~961,528) is below the 2,300,000 floor, so the Manager
        // must earn NO incentive at all.
        $manager = $this->manager(15, 961528.2);

        $performance = (new AchievementCalculatorService)->getPerformance($manager);

        $this->assertSame(0.0, $performance['incentive']);

        $breakdown = (new AchievementCalculatorService)->getManagerIncentiveBreakdown($manager);

        $this->assertSame(0.0, $breakdown['multiplier']);
        $this->assertSame(0.0, $breakdown['incentive']);
    }

    public function test_manager_incentive_uses_ppp_multiplier_not_the_flat_caller_slab(): void
    {
        // 3 callers @ 5,000,000 each -> team total 15,000,000, PPP 5,000,000
        // (>= 3,100,000 band -> 0.00075 multiplier).
        // Flat caller slab table would give a capped 70,000 (>= 11,000,000
        // slab) — the PPP formula must give 15,000,000 * 0.00075 = 11,250.
        $manager = $this->manager(3, 5000000);

        $performance = (new AchievementCalculatorService)->getPerformance($manager);

        $this->assertSame(11250.0, $performance['incentive']);
    }

    public function test_cluster_manager_incentive_uses_the_manager_ppp_multiplier_not_the_flat_caller_slab(): void
    {
        // Same branch figures as the Manager case one level down: 3 callers
        // @ 5,000,000 -> 15,000,000 branch achievement, PPP 5,000,000 ->
        // 0.00075. The caller slab would pay the capped 70,000.
        $cluster = $this->cluster(3, 5000000);

        $calculator = new AchievementCalculatorService;

        $this->assertSame(AchievementCalculatorService::INCENTIVE_SCHEME_MANAGER, $calculator->incentiveSchemeFor($cluster));
        $this->assertSame(11250.0, $calculator->getPerformance($cluster)['incentive']);
        $this->assertSame(11250.0, $calculator->getManagerIncentiveBreakdown($cluster)['incentive']);
    }

    public function test_cluster_manager_earns_nothing_when_branch_ppp_is_below_the_floor_even_though_branch_achievement_exceeds_every_flat_slab(): void
    {
        // Employee Lookup ME0045: a Cluster Manager's ₹2.1 Cr branch was paid
        // the top caller slab (70,000). 15 callers @ ~961,528 -> ~14,422,923
        // branch achievement but PPP ~961,528, below the 2,300,000 floor.
        $cluster = $this->cluster(15, 961528.2);

        $calculator = new AchievementCalculatorService;
        $breakdown = $calculator->getManagerIncentiveBreakdown($cluster);

        $this->assertSame(15, $breakdown['eligible_callers']);
        $this->assertSame(0.0, $breakdown['multiplier']);
        $this->assertSame(0.0, $calculator->getPerformance($cluster)['incentive']);
    }

    public function test_each_designation_is_paid_on_its_own_scheme(): void
    {
        $calculator = new AchievementCalculatorService;

        $schemeOf = fn (int $designation): string => $calculator->incentiveSchemeFor(
            Employee::factory()->create(['designation' => $designation])
        );

        $this->assertSame(AchievementCalculatorService::INCENTIVE_SCHEME_SLAB, $schemeOf(Employee::DESIGNATION_CALLER));
        $this->assertSame(AchievementCalculatorService::INCENTIVE_SCHEME_TEAM_LEADER, $schemeOf(Employee::DESIGNATION_TEAM_LEADER));
        $this->assertSame(AchievementCalculatorService::INCENTIVE_SCHEME_MANAGER, $schemeOf(Employee::DESIGNATION_MANAGER));
        $this->assertSame(AchievementCalculatorService::INCENTIVE_SCHEME_MANAGER, $schemeOf(Employee::DESIGNATION_CLUSTER));
    }

    public function test_employee_lookup_explains_a_cluster_managers_incentive_with_the_ppp_scheme(): void
    {
        $cluster = $this->cluster(3, 5000000);
        $period = EmployeeLookupPeriod::forMonth(now());
        $breakdowns = app(EmployeeFigureBreakdownService::class);

        $incentive = $breakdowns->incentive($cluster, $period);

        $this->assertSame('manager', $incentive['kind']);
        $this->assertSame(3, $incentive['eligible_callers']);
        $this->assertSame(11250.0, $incentive['incentive']);
        $this->assertSame(11250.0, $breakdowns->summary($cluster, $period)['incentive']);
    }

    public function test_the_manager_ppp_widget_shows_for_managers_and_cluster_managers_only(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $canView = function (int $designation): bool {
            $employee = Employee::factory()->create(['designation' => $designation]);

            $this->actingAs(User::factory()->create(['employee_id' => $employee->id]));

            return ManagerPPPStats::canView();
        };

        $this->assertTrue($canView(Employee::DESIGNATION_MANAGER));
        $this->assertTrue($canView(Employee::DESIGNATION_CLUSTER));
        $this->assertFalse($canView(Employee::DESIGNATION_TEAM_LEADER));
        $this->assertFalse($canView(Employee::DESIGNATION_CALLER));
    }
}
