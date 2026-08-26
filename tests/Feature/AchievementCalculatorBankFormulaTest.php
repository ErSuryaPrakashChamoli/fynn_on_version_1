<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementCalculatorBankFormulaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bfl_prime_halves_the_deduction(): void
    {
        $caller = $this->callerWithOneCustomer('BFL Prime', 3000000, 10000, 5000, '5000');

        $countAchievement = (new AchievementCalculatorService)->getCountAchievement($caller);

        // 3,000,000 - ((10000 + 5000 + 5000) / 2) * 100 = 3,000,000 - 1,000,000
        $this->assertSame(2000000.0, $countAchievement);
    }

    public function test_bfl_growth_halves_the_deduction(): void
    {
        $caller = $this->callerWithOneCustomer('BFL Growth', 3000000, 10000, 5000, '5000');

        $countAchievement = (new AchievementCalculatorService)->getCountAchievement($caller);

        $this->assertSame(2000000.0, $countAchievement);
    }

    public function test_other_bank_applies_the_full_deduction(): void
    {
        $caller = $this->callerWithOneCustomer('HDFC Bank', 3000000, 10000, 5000, '5000');

        $countAchievement = (new AchievementCalculatorService)->getCountAchievement($caller);

        // 3,000,000 - (10000 + 5000 + 5000) * 100 = 3,000,000 - 2,000,000
        $this->assertSame(1000000.0, $countAchievement);
    }

    public function test_no_bank_recorded_defaults_to_the_full_deduction(): void
    {
        $caller = $this->callerWithOneCustomer(null, 3000000, 10000, 5000, '5000');

        $countAchievement = (new AchievementCalculatorService)->getCountAchievement($caller);

        $this->assertSame(1000000.0, $countAchievement);
    }

    public function test_mixed_banks_within_the_same_hierarchy_are_deducted_independently(): void
    {
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'BFL Prime',
            'sanctioned_loan_amount' => 2000000,
            'cashback' => 10000,
            'subvention' => 0,
            'docking' => '0',
        ]);
        // net = 2,000,000 - ((10000 + 0 + 0) / 2) * 100 = 1,500,000

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 2000,
            'subvention' => 0,
            'docking' => '0',
        ]);
        // net = 1,000,000 - (2000 + 0 + 0) * 100 = 800,000

        $countAchievement = (new AchievementCalculatorService)->getCountAchievement($caller);

        $this->assertSame(2300000.0, $countAchievement);
    }

    public function test_get_performance_and_get_count_achievement_agree(): void
    {
        $caller = $this->callerWithOneCustomer('HDFC Bank', 3000000, 10000, 5000, '5000');

        $calculator = new AchievementCalculatorService;

        $this->assertSame(
            $calculator->getCountAchievement($caller),
            $calculator->getPerformance($caller)['count_achievement']
        );
    }

    private function callerWithOneCustomer(
        ?string $bank,
        float $loanAmount,
        float $cashback,
        float $subvention,
        ?string $docking
    ): Employee {
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => $bank,
            'sanctioned_loan_amount' => $loanAmount,
            'cashback' => $cashback,
            'subvention' => $subvention,
            'docking' => $docking,
        ]);

        return $caller;
    }
}
