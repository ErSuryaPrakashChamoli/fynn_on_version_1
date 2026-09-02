<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for a bug discovered while auditing customers.docking:
 * computeAchievementTotals()'s single combined per-row expression means a
 * NULL anywhere in that row's cashback/subvention/docking/loan-amount
 * inputs makes the ENTIRE row's contribution NULL under normal SQL
 * arithmetic — and SQL's SUM() silently skips NULL rows, so that customer
 * disappeared from the achievement total entirely (not just their
 * deduction being treated as 0). This was a real regression introduced
 * when the formula was consolidated into one combined expression for the
 * BFL Prime/Growth per-loan fix earlier this session — every affected
 * field is now wrapped in COALESCE(..., 0) inside that one expression so a
 * missing value contributes exactly 0, never poisoning the whole row.
 *
 * Also covers customers.docking specifically: it's varchar, not decimal
 * (cashback/subvention are proper decimal columns and were exposed to the
 * same NULL-propagation risk) — inspected live data before writing these
 * tests (27 customers, 12 with a non-null docking value, all 12 clean
 * numeric, zero malformed/legacy values found) — so a malformed-value test
 * here exercises a case that doesn't exist in current data, proving the
 * defensive CAST doesn't crash if one ever appears, not fixing live
 * corruption.
 */
class AchievementCalculatorNullDeductionFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function callerWithCustomer(array $customerAttributes): Employee
    {
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        Customer::factory()->create(array_merge([
            'employee_id' => $caller->id,
            'disbursal_date' => now(),
        ], $customerAttributes));

        return $caller;
    }

    public function test_all_deduction_fields_null_no_longer_drops_the_customer_from_the_total(): void
    {
        $caller = $this->callerWithCustomer([
            'sanctioned_loan_amount' => 1000000,
            'cashback' => null,
            'subvention' => null,
            'docking' => null,
        ]);

        $result = (new AchievementCalculatorService)->getCountAchievement($caller);

        $this->assertSame(1000000.0, $result);
    }

    public function test_only_docking_null_still_counts_the_row_with_the_other_deductions_applied(): void
    {
        $caller = $this->callerWithCustomer([
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 10000,
            'subvention' => 0,
            'docking' => null,
        ]);

        // Full deduction (non-BFL/null bank): 1,000,000 - (10000)*100 = 0
        $result = (new AchievementCalculatorService)->getCountAchievement($caller);

        $this->assertSame(0.0, $result);
    }

    public function test_docking_as_a_clean_numeric_string_is_included_correctly(): void
    {
        $caller = $this->callerWithCustomer([
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '2000',
        ]);

        // 1,000,000 - (2000)*100 = 800,000
        $this->assertSame(800000.0, (new AchievementCalculatorService)->getCountAchievement($caller));
    }

    public function test_docking_as_an_explicit_zero_string_is_treated_as_zero_not_skipped(): void
    {
        $caller = $this->callerWithCustomer([
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 5000,
            'subvention' => 0,
            'docking' => '0',
        ]);

        // 1,000,000 - (5000)*100 = 500,000
        $this->assertSame(500000.0, (new AchievementCalculatorService)->getCountAchievement($caller));
    }

    public function test_a_malformed_legacy_docking_value_does_not_crash_and_is_treated_as_zero(): void
    {
        // No such value exists in current data (verified before writing
        // this test) — this only proves the defensive CAST behaves
        // predictably if one is ever introduced (e.g. a bad legacy import),
        // rather than throwing a SQL type error.
        $caller = $this->callerWithCustomer([
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => 'N/A',
        ]);

        $result = (new AchievementCalculatorService)->getCountAchievement($caller);

        $this->assertSame(1000000.0, $result);
    }

    public function test_null_loan_amount_with_no_mis_override_contributes_zero_actual_but_does_not_drop_the_row(): void
    {
        $caller = $this->callerWithCustomer([
            'sanctioned_loan_amount' => null,
            'cashback' => 1000,
            'subvention' => 0,
            'docking' => '0',
        ]);

        // actual = 0 (COALESCE), deduction = 1000*100 = 100,000, floored at
        // the raw formula (no max(0,...) clamp exists here — matches
        // existing, unchanged formula behavior for a net-negative result).
        $result = (new AchievementCalculatorService)->getCountAchievement($caller);

        $this->assertSame(-100000.0, $result);
    }

    public function test_getperformance_totals_are_consistent_with_get_count_achievement_when_fields_are_null(): void
    {
        $caller = $this->callerWithCustomer([
            'sanctioned_loan_amount' => 500000,
            'cashback' => null,
            'subvention' => null,
            'docking' => null,
        ]);

        $calculator = new AchievementCalculatorService;

        $this->assertSame(
            $calculator->getCountAchievement($caller),
            $calculator->getPerformance($caller)['count_achievement']
        );
        $this->assertSame(500000.0, $calculator->getPerformance($caller)['count_achievement']);
    }
}
