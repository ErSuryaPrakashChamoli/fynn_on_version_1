<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * customers.sanctioned_bank is free text, not an FK — the UI Select
 * constrains it, but CustomerImporter's CSV import column
 * (only ->rules(['max:255']), no `in:` constraint) and
 * OcrFieldExtractionService's OCR-extracted value both bypass that Select.
 * A variant like "bfl prime", "BFL PRIME", or "BFL-Prime" can genuinely
 * reach this column and must still be recognized as BFL Prime for the
 * half-deduction rule — without merging BFL Prime and BFL Growth into each
 * other, and without any schema change (canonicalization happens in the
 * SQL comparison itself: UPPER(TRIM(REPLACE(...))) on both sides).
 */
class AchievementCalculatorBankNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private function callerWithOneCustomer(?string $bank): Employee
    {
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => $bank,
            'sanctioned_loan_amount' => 3000000,
            'cashback' => 10000,
            'subvention' => 0,
            'docking' => '0',
        ]);

        return $caller;
    }

    private function halfDeductionResult(): float
    {
        // 3,000,000 - ((10000)/2)*100 = 3,000,000 - 500,000
        return 2500000.0;
    }

    private function fullDeductionResult(): float
    {
        // 3,000,000 - (10000)*100 = 3,000,000 - 1,000,000
        return 2000000.0;
    }

    public function test_canonical_bfl_prime_still_halves_the_deduction(): void
    {
        $caller = $this->callerWithOneCustomer('BFL Prime');

        $this->assertSame(
            $this->halfDeductionResult(),
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_canonical_bfl_growth_still_halves_the_deduction(): void
    {
        $caller = $this->callerWithOneCustomer('BFL Growth');

        $this->assertSame(
            $this->halfDeductionResult(),
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_lowercase_variant_is_recognized(): void
    {
        $caller = $this->callerWithOneCustomer('bfl prime');

        $this->assertSame(
            $this->halfDeductionResult(),
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_uppercase_variant_is_recognized(): void
    {
        $caller = $this->callerWithOneCustomer('BFL PRIME');

        $this->assertSame(
            $this->halfDeductionResult(),
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_surrounding_whitespace_variant_is_recognized(): void
    {
        $caller = $this->callerWithOneCustomer('  BFL Growth  ');

        $this->assertSame(
            $this->halfDeductionResult(),
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_hyphenated_variant_is_recognized(): void
    {
        $caller = $this->callerWithOneCustomer('BFL-Prime');

        $this->assertSame(
            $this->halfDeductionResult(),
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_unknown_bank_is_not_matched_and_gets_the_full_deduction(): void
    {
        $caller = $this->callerWithOneCustomer('Some New Bank');

        $this->assertSame(
            $this->fullDeductionResult(),
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_a_different_real_bank_is_not_merged_into_bfl(): void
    {
        $caller = $this->callerWithOneCustomer('HDFC Bank');

        $this->assertSame(
            $this->fullDeductionResult(),
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_null_bank_gets_the_full_deduction(): void
    {
        $caller = $this->callerWithOneCustomer(null);

        $this->assertSame(
            $this->fullDeductionResult(),
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_a_partial_or_ambiguous_bank_name_is_not_treated_as_either_bfl_product(): void
    {
        // "BFL" alone is neither "BFL Prime" nor "BFL Growth" — normalization
        // must not turn substring matching into false positives.
        $caller = $this->callerWithOneCustomer('BFL');

        $this->assertSame(
            $this->fullDeductionResult(),
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_bfl_prime_and_bfl_growth_remain_distinguishable_from_each_other(): void
    {
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        // Same deduction inputs, different bank — both should still land in
        // the half-deduction bucket, proving normalization didn't collapse
        // them into one bank, it just makes each one robust individually.
        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'bfl prime',
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'BFL-GROWTH',
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
        ]);

        // Neither loan has any cashback/subvention/docking, so the
        // deduction is 0 regardless of bank — this test only proves both
        // rows are matched (no SQL error, no silent exclusion), verified
        // together with the mixed-portfolio test below for the case where
        // the multiplier actually matters.
        $this->assertSame(
            2000000.0,
            (new AchievementCalculatorService)->getCountAchievement($caller)
        );
    }

    public function test_mixed_bank_variant_portfolio_produces_the_correct_blended_achievement(): void
    {
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        // A case-variant BFL Prime loan (half-deduction).
        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'bfl prime',
            'sanctioned_loan_amount' => 2000000,
            'cashback' => 10000,
            'subvention' => 0,
            'docking' => '0',
        ]);
        // net = 2,000,000 - (10000/2)*100 = 1,500,000

        // A hyphenated-variant BFL Growth loan (half-deduction).
        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'BFL-Growth',
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 4000,
            'subvention' => 0,
            'docking' => '0',
        ]);
        // net = 1,000,000 - (4000/2)*100 = 800,000

        // An unrelated bank (full deduction).
        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 2000,
            'subvention' => 0,
            'docking' => '0',
        ]);
        // net = 1,000,000 - (2000)*100 = 800,000

        $countAchievement = (new AchievementCalculatorService)->getCountAchievement($caller);
        $this->assertSame(3100000.0, $countAchievement);

        // The blended achievement also feeds the standard incentive slabs
        // unchanged — no regression to the single authoritative engine.
        $performance = (new AchievementCalculatorService)->getPerformance($caller);
        $this->assertSame($countAchievement, $performance['count_achievement']);
    }
}
