<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
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

        Employee::factory()->count($callerCount)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => null,
        ])->each(function (Employee $caller) use ($loanAmountPerCaller) {
            Customer::factory()->create([
                'employee_id' => $caller->id,
                'sanctioned_loan_amount' => $loanAmountPerCaller,
                'cashback' => 0,
                'subvention' => 0,
                'docking' => '0',
            ]);
        });

        return $manager;
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
}
