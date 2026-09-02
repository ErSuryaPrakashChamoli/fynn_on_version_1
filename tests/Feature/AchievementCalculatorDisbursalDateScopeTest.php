<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achievement/target/incentive figures must reflect when a loan was
 * actually disbursed, not when the lead record was created — a loan
 * created in one month can easily be disbursed weeks later, and previously
 * every achievement figure (getCountAchievement/getPerformance/team-leader
 * incentive) was scoped by customers.created_at, silently attributing a
 * sale to the wrong month's target whenever those two dates diverged.
 */
class AchievementCalculatorDisbursalDateScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 8, 27));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_get_count_achievement_ignores_created_at_and_uses_disbursal_date(): void
    {
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        // Created in August (the selected/current month) but only disbursed
        // in July — must NOT count toward August's achievement.
        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => 5000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'created_at' => Carbon::create(2026, 8, 20),
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 7, 5),
        ]);

        // Created back in June but disbursed in August — MUST count toward
        // August's achievement.
        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => 2000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'created_at' => Carbon::create(2026, 6, 1),
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 8, 10),
        ]);

        $achievement = (new AchievementCalculatorService)->getCountAchievement($caller);

        $this->assertSame(2000000.0, $achievement);
    }

    public function test_team_leader_incentive_breakdown_scopes_team_achievement_by_disbursal_date(): void
    {
        $teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER]);

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        // Disbursed last month — must not feed this month's team achievement.
        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => 9000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 7, 1),
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => 2500000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 8, 15),
        ]);

        $breakdown = (new AchievementCalculatorService)->getTeamLeaderIncentiveBreakdown($teamLeader);

        $this->assertSame(2500000.0, $breakdown['team_achievement']);
    }

    public function test_falls_back_to_last_month_with_data_when_the_real_current_month_has_no_disbursals_yet(): void
    {
        // "Today" is the 2nd of August — the real current month — but
        // nobody has been disbursed in August yet (loans take days/weeks
        // to clear), while July was a normal, fully-disbursed month.
        Carbon::setTestNow(Carbon::create(2026, 8, 2));

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => 2000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 7, 20),
        ]);

        $calculator = new AchievementCalculatorService;

        // Without the fallback this would be 0 — August has no disbursals.
        $this->assertSame(2000000.0, $calculator->getCountAchievement($caller));

        // Target must be July's too, not August's, so the percentage pairs
        // achievement and target from the same month.
        $performance = $calculator->getPerformance($caller);
        $this->assertSame(2000000.0, $performance['count_achievement']);
        $this->assertSame(2500000.0, $performance['target']);

        // The resolved month is exposed so callers (e.g. the marquee) can
        // label which month's figures are actually being shown.
        $this->assertSame('2026-07', $calculator->resolveReferenceMonth()->format('Y-m'));
    }

    public function test_does_not_fall_back_when_the_current_month_already_has_disbursal_data(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 20));

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 8, 5),
        ]);

        $calculator = new AchievementCalculatorService;

        $this->assertSame('2026-08', $calculator->resolveReferenceMonth()->format('Y-m'));
        $this->assertSame(1000000.0, $calculator->getCountAchievement($caller));
    }

    public function test_target_boundary_logic_is_never_shifted_by_the_achievement_fallback(): void
    {
        // No customers at all this month — achievement fallback would kick
        // in for getCountAchievement(), but a caller's own hierarchy
        // target boundary (joining-date worked-days rule) must still be
        // evaluated against the REAL current month, not silently moved.
        Carbon::setTestNow(Carbon::create(2026, 8, 15));

        $newJoiner = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => Carbon::create(2026, 8, 14), // 2 worked days -> 0 target
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($newJoiner);

        $this->assertSame(0.0, $target);
    }
}
