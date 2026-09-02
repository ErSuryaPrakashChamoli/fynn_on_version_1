<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementCalculatorTeamLeaderIncentiveTest extends TestCase
{
    use RefreshDatabase;

    private function teamLeaderWithCallers(int $callerCount, array $achievementPerCaller): Employee
    {
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
        ]);

        for ($i = 0; $i < $callerCount; $i++) {
            $caller = Employee::factory()->create([
                'designation' => Employee::DESIGNATION_CALLER,
                'superviser_id' => $teamLeader->id,
                'category' => '2500000',
                'reporting_date' => null,
            ]);

            Customer::factory()->create([
                'employee_id' => $caller->id,
                'sanctioned_bank' => 'HDFC Bank',
                'sanctioned_loan_amount' => $achievementPerCaller[$i],
                'cashback' => 0,
                'subvention' => 0,
                'docking' => '0',
                'disbursal_date' => now(),
            ]);
        }

        return $teamLeader;
    }

    public function test_five_caller_team_hitting_target_earns_the_size_based_revenue_share_plus_bonus(): void
    {
        // 5 callers @ target (2,500,000 each) exactly -> 100% achievement.
        $teamLeader = $this->teamLeaderWithCallers(5, array_fill(0, 5, 2500000));

        $breakdown = (new AchievementCalculatorService)->getTeamLeaderIncentiveBreakdown($teamLeader);

        $this->assertSame(12500000.0, $breakdown['team_target']);
        $this->assertSame(12500000.0, $breakdown['team_achievement']);
        $this->assertTrue($breakdown['meets_gate']);
        $this->assertTrue($breakdown['all_callers_achieved']);

        // revenue = 250,000; grossRevenue = 250,000 - 5*30,000 = 100,000;
        // grossRevenuePercent (5 callers) = 8%; extraRevenue = 0;
        // base = 100,000 * 0.08 = 8,000; +10% all-achieved bonus = 8,800.
        $this->assertSame(8800.0, $breakdown['incentive']);
    }

    public function test_four_caller_team_requires_100_percent_not_90_percent(): void
    {
        // 3 callers hit target exactly, 1 falls short by 500,000.
        // Team: target 10,000,000, achievement 9,500,000 -> 95%.
        $teamLeader = $this->teamLeaderWithCallers(4, [2500000, 2500000, 2500000, 2000000]);

        $breakdown = (new AchievementCalculatorService)->getTeamLeaderIncentiveBreakdown($teamLeader);

        $this->assertSame(10000000.0, $breakdown['team_target']);
        $this->assertSame(9500000.0, $breakdown['team_achievement']);
        $this->assertSame(100.0, $breakdown['required_percentage']);
        $this->assertFalse($breakdown['meets_gate']);
        $this->assertSame(0.0, $breakdown['incentive']);
    }

    public function test_five_caller_team_only_needs_90_percent_for_the_same_shortfall(): void
    {
        // Same per-caller shortfall as above, but a 5th caller who hits
        // target exactly is added -> team achievement 12,000,000 against
        // target 12,500,000 = 96%, which clears the 90% gate that applies
        // once the team has more than 4 callers.
        $teamLeader = $this->teamLeaderWithCallers(5, [2500000, 2500000, 2500000, 2000000, 2500000]);

        $breakdown = (new AchievementCalculatorService)->getTeamLeaderIncentiveBreakdown($teamLeader);

        $this->assertSame(90.0, $breakdown['required_percentage']);
        $this->assertTrue($breakdown['meets_gate']);
        $this->assertFalse($breakdown['all_callers_achieved']);

        // revenue = 240,000; grossRevenue = 240,000 - 5*30,000 = 90,000;
        // grossRevenuePercent (5 callers) = 8%; extraRevenue = max(0, 240,000 - 250,000) = 0;
        // base = 90,000 * 0.08 = 7,200; no bonus (not everyone achieved).
        $this->assertSame(7200.0, $breakdown['incentive']);
    }

    public function test_understaffed_team_is_not_hard_zeroed_only_the_existing_target_topup_applies(): void
    {
        // 2 callers, each exceeding their own target -> team achievement
        // (6,000,000) exceeds team target (5,000,000). There is no <3
        // hard-zero gate here (only the existing 30L target top-up on the
        // TL's own target elsewhere handles understaffed teams) — with
        // achievement above target, this team must still earn a nonzero
        // incentive via the "excess revenue" share.
        $teamLeader = $this->teamLeaderWithCallers(2, [3000000, 3000000]);

        $breakdown = (new AchievementCalculatorService)->getTeamLeaderIncentiveBreakdown($teamLeader);

        $this->assertTrue($breakdown['meets_gate']);

        // revenue = 120,000; grossRevenue = max(0, 120,000 - 2*30,000) = 60,000;
        // grossRevenuePercent (2 callers, below the 3/4/5+ bands) = 0%;
        // extraRevenue = max(0, 120,000 - 100,000) = 20,000;
        // base = 60,000*0 + 20,000*0.05 = 1,000. No bonus (< 3 callers).
        $this->assertSame(1000.0, $breakdown['incentive']);
    }

    public function test_bank_aware_deduction_applies_to_team_achievement(): void
    {
        // Single caller under a half-deduction bank (BFL Prime): the team
        // achievement must go through computeAchievementTotals(), not a
        // raw sanctioned_loan_amount sum, so the bank-aware deduction rule
        // is honoured.
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
        ]);

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'BFL Prime',
            'sanctioned_loan_amount' => 3000000,
            'cashback' => 10000,
            'subvention' => 5000,
            'docking' => '5000',
            'disbursal_date' => now(),
        ]);

        $breakdown = (new AchievementCalculatorService)->getTeamLeaderIncentiveBreakdown($teamLeader);

        // 3,000,000 - ((10000 + 5000 + 5000) / 2) * 100 = 2,000,000
        $this->assertSame(2000000.0, $breakdown['team_achievement']);
    }

    public function test_incentive_flows_through_get_performance_for_team_leader(): void
    {
        $teamLeader = $this->teamLeaderWithCallers(5, array_fill(0, 5, 2500000));

        $performance = (new AchievementCalculatorService)->getPerformance($teamLeader);

        $this->assertSame(8800.0, $performance['incentive']);
    }

    public function test_single_eligible_caller_is_a_hard_zero_even_when_target_is_far_exceeded(): void
    {
        // 1 caller, achieving well above target (100% gate trivially met,
        // 160% achievement) -> without the hard zero this would earn a
        // nonzero "excess revenue" share. The single-eligible-caller rule
        // must override that and force 0.
        $teamLeader = $this->teamLeaderWithCallers(1, [4000000]);

        $breakdown = (new AchievementCalculatorService)->getTeamLeaderIncentiveBreakdown($teamLeader);

        $this->assertSame(1, $breakdown['eligible_callers']);
        $this->assertSame(0.0, $breakdown['incentive']);
        $this->assertFalse($breakdown['meets_gate']);
    }

    public function test_single_eligible_caller_hard_zero_applies_even_with_two_raw_callers(): void
    {
        // 2 callers on the roster, but one is a brand-new joiner with only
        // 3 days worked this month -> their target is 0, so
        // getEligibleCallerCount() excludes them (eligible_callers = 1),
        // even though the raw caller_count is 2. Without the hard zero,
        // this scenario (achievement well above the reduced team target)
        // would earn a nonzero incentive via the "excess revenue" share.
        Carbon::setTestNow(Carbon::create(2026, 8, 27));

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
        ]);

        $existingCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        Customer::factory()->create([
            'employee_id' => $existingCaller->id,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => 4000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_date' => now(),
        ]);

        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'category' => '2500000',
            'reporting_date' => Carbon::create(2026, 8, 25),
        ]);

        $breakdown = (new AchievementCalculatorService)->getTeamLeaderIncentiveBreakdown($teamLeader);

        $this->assertSame(2, $breakdown['caller_count']);
        $this->assertSame(1, $breakdown['eligible_callers']);
        $this->assertSame(0.0, $breakdown['incentive']);

        Carbon::setTestNow();
    }
}
