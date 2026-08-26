<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSettlement;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use App\Services\Settlement\MisSettlementService;
use App\Services\Settlement\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * mis_gst / mis_tds / actual_payable_amount are NOT NULL DEFAULT 0 at the
 * database level (unlike mis_disbursal_amount/cashback/subvention/docking,
 * which are genuinely nullable) — so the NULL-vs-zero distinction the
 * business rule cares about can only ever be honored at the moment an
 * update is applied, never recovered later from the persisted column
 * alone. This class verifies that moment is now handled correctly.
 */
class MisNullVsZeroFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = 1');
        }
    }

    private function settlement(): CustomerSettlement
    {
        $customer = Customer::factory()->create([
            'sanctioned_loan_amount' => 3000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
        ]);

        return app(SettlementService::class)->createSalesSnapshot($customer);
    }

    public function test_an_explicit_zero_gst_reported_by_the_bank_is_honored_not_overridden(): void
    {
        $settlement = $this->settlement();

        // Give it a positive expected GST baseline so we can prove an
        // explicit bank-reported 0 is NOT silently replaced by it.
        $settlement->update(['gross_payable_amount' => 100000, 'gst_rate' => 18]);

        $updated = app(MisSettlementService::class)->updateFromMis(
            settlement: $settlement,
            data: ['mis_gst' => 0, 'mis_disbursal_amount' => 3000000],
        );

        $this->assertEquals(0.0, (float) $updated->mis_gst);
        $this->assertEquals(0.0, (float) $updated->gst_amount);
        $this->assertNotEquals(18000.0, (float) $updated->gst_amount);
    }

    public function test_a_previously_confirmed_gst_value_is_not_wiped_or_crashed_by_a_later_blank_cell(): void
    {
        $settlement = $this->settlement();

        // First import: bank confirms a real GST value.
        $afterFirst = app(MisSettlementService::class)->updateFromMis(
            settlement: $settlement,
            data: ['mis_gst' => 900, 'mis_disbursal_amount' => 3000000],
        );

        $this->assertEquals(900.0, (float) $afterFirst->mis_gst);
        $this->assertEquals(900.0, (float) $afterFirst->gst_amount);

        // Second import/edit: the cell for mis_gst comes back blank (the
        // key is present — e.g. a CSV column that exists but is empty for
        // this row — with a null value), while a different field is
        // revised. This must not attempt to persist NULL into a NOT NULL
        // column, and must not silently discard the previously-confirmed
        // value.
        $afterSecond = app(MisSettlementService::class)->updateFromMis(
            settlement: $afterFirst,
            data: ['mis_gst' => null, 'mis_cashback' => 5000],
        );

        $this->assertEquals(900.0, (float) $afterSecond->mis_gst);
        $this->assertEquals(900.0, (float) $afterSecond->gst_amount);
    }

    public function test_tds_and_payable_amount_have_the_same_protection_as_gst(): void
    {
        $settlement = $this->settlement();

        $afterFirst = app(MisSettlementService::class)->updateFromMis(
            settlement: $settlement,
            data: [
                'mis_tds' => 400,
                'actual_payable_amount' => 250000,
                'mis_disbursal_amount' => 3000000,
            ],
        );

        $this->assertEquals(400.0, (float) $afterFirst->mis_tds);
        $this->assertEquals(250000.0, (float) $afterFirst->actual_payable_amount);
        $this->assertEquals(400.0, (float) $afterFirst->tds_amount);
        $this->assertEquals(250000.0, (float) $afterFirst->net_payable_amount);

        $afterSecond = app(MisSettlementService::class)->updateFromMis(
            settlement: $afterFirst,
            data: ['mis_tds' => null, 'actual_payable_amount' => null, 'mis_cashback' => 1000],
        );

        $this->assertEquals(400.0, (float) $afterSecond->mis_tds);
        $this->assertEquals(250000.0, (float) $afterSecond->actual_payable_amount);
        $this->assertEquals(400.0, (float) $afterSecond->tds_amount);
        $this->assertEquals(250000.0, (float) $afterSecond->net_payable_amount);
    }

    public function test_a_field_never_reported_by_the_bank_still_falls_back_to_the_expected_value(): void
    {
        $settlement = $this->settlement();
        $settlement->update(['gross_payable_amount' => 100000, 'gst_rate' => 18]);

        $updated = app(MisSettlementService::class)->updateFromMis(
            settlement: $settlement,
            data: ['mis_disbursal_amount' => 3000000],
        );

        $this->assertEquals(0.0, (float) $updated->mis_gst);
        $this->assertEquals(18000.0, (float) $updated->gst_amount);
    }

    public function test_incentive_and_achievement_calculation_is_unaffected_by_these_fields(): void
    {
        // mis_gst/mis_tds/actual_payable_amount feed reconciliation/payment
        // figures only — they must never enter the achievement/incentive
        // formula (which uses mis_disbursal_amount/cashback/subvention/docking).
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        $customer = Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => 3000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
        ]);
        $settlement = app(SettlementService::class)->createSalesSnapshot($customer);

        $calculator = app(AchievementCalculatorService::class);
        $before = $calculator->getPerformance($caller)['count_achievement'];

        app(MisSettlementService::class)->updateFromMis(
            settlement: $settlement,
            data: ['mis_gst' => 999999, 'mis_tds' => 999999, 'actual_payable_amount' => 999999],
        );

        $after = $calculator->getPerformance($caller)['count_achievement'];

        $this->assertSame($before, $after);
    }
}
