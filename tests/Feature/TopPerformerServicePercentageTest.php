<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use App\Services\TopPerformerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TopPerformerService previously reimplemented the achievement-percentage
 * formula (achievement/target*100, rounded to 2 decimals) independently of
 * AchievementCalculatorService::getPercentage(), which uses the identical
 * formula and precision. This verifies the two now share one authoritative
 * implementation (percentageFromAmounts()) and produce identical output.
 *
 * IncentiveStats/PerformanceStats also recompute a percentage locally, but
 * deliberately round to 1 decimal (consistently, in both places) purely
 * for display — they are not independently re-deriving target/achievement,
 * just re-rounding numbers AchievementCalculatorService already computed.
 * That is an intentional display choice, not a competing calculation, so
 * it is left untouched.
 */
class TopPerformerServicePercentageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // TopPerformerService caches by designation; the array driver
        // persists across test methods within one PHPUnit process.
        Cache::flush();
    }

    private function callerWithAchievement(float $loanAmount, float $categoryTarget): Employee
    {
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => (string) $categoryTarget,
            'exit_status' => 'no',
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => $loanAmount,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
        ]);

        return $caller;
    }

    public function test_percentage_from_amounts_matches_get_percentage_for_the_same_employee(): void
    {
        $caller = $this->callerWithAchievement(2000000, 4000000);

        $calculator = new AchievementCalculatorService;

        $viaGetPercentage = $calculator->getPercentage($caller);
        $viaAmounts = $calculator->percentageFromAmounts(
            $calculator->getCountAchievement($caller),
            $calculator->getTarget($caller)
        );

        $this->assertSame($viaGetPercentage, $viaAmounts);
        $this->assertSame(50.0, $viaGetPercentage);
    }

    public function test_percentage_from_amounts_returns_zero_for_a_non_positive_target(): void
    {
        $calculator = new AchievementCalculatorService;

        $this->assertSame(0.0, $calculator->percentageFromAmounts(500000, 0));
        $this->assertSame(0.0, $calculator->percentageFromAmounts(500000, -100));
    }

    public function test_top_performer_service_percentage_matches_the_authoritative_engine(): void
    {
        $caller = $this->callerWithAchievement(3000000, 4000000);

        $performers = (new TopPerformerService(new AchievementCalculatorService))
            ->getTopPerformers($caller);

        $performer = collect($performers)->firstWhere('name', $caller->emp_name);

        $this->assertNotNull($performer);

        $expected = (new AchievementCalculatorService)->getPercentage($caller);

        $this->assertSame($expected, $performer['percentage']);
        $this->assertSame(75.0, $performer['percentage']);
    }

    public function test_top_performer_ranking_is_unaffected_by_the_consolidation(): void
    {
        $strong = $this->callerWithAchievement(4000000, 4000000); // 100%
        $weak = $this->callerWithAchievement(1000000, 4000000); // 25%

        $performers = (new TopPerformerService(new AchievementCalculatorService))
            ->getTopPerformers($strong);

        $names = collect($performers)->pluck('name')->all();

        $this->assertSame($strong->emp_name, $names[0]);
        $this->assertContains($weak->emp_name, $names);
    }
}
