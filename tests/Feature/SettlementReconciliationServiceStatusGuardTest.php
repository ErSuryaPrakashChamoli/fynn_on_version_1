<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSettlement;
use App\Services\Settlement\SettlementReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettlementReconciliationServiceStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Production (MySQL) already widens the `status` ENUM to include
        // mis_review/mis_verified/accounts_review/recovery_pending via
        // 2026_08_18_170002_update_customer_settlement_statuses_for_mis_accounts.php,
        // but that migration is MySQL-only (guarded by DB::getDriverName())
        // so the sqlite in-memory test database is stuck with the original,
        // narrower CHECK constraint from the base migration. This pragma
        // relaxes that check for this test's sqlite connection only, so the
        // test DB reflects the same allowed values production already has.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = 1');
        }
    }

    public function test_recovery_pending_never_overrides_mis_review_status(): void
    {
        $settlement = $this->makeSettlementNeedingRecovery('mis_review');

        (new SettlementReconciliationService)->calculate($settlement);

        $this->assertSame('mis_review', $settlement->fresh()->status);
    }

    public function test_recovery_pending_never_overrides_mis_verified_status(): void
    {
        $settlement = $this->makeSettlementNeedingRecovery('mis_verified');

        (new SettlementReconciliationService)->calculate($settlement);

        $this->assertSame('mis_verified', $settlement->fresh()->status);
    }

    public function test_recovery_pending_still_applies_once_mis_has_finished_with_the_case(): void
    {
        $settlement = $this->makeSettlementNeedingRecovery('accounts_review');

        (new SettlementReconciliationService)->calculate($settlement);

        $this->assertSame('recovery_pending', $settlement->fresh()->status);
    }

    private function makeSettlementNeedingRecovery(string $status): CustomerSettlement
    {
        $customer = Customer::factory()->create();

        return CustomerSettlement::create([
            'settlement_no' => 'SET-RECOVERY-'.$status,
            'customer_id' => $customer->id,
            'version' => 1,
            'status' => $status,
            'payment_status' => 'paid',
            'cancellation_recovery' => 10000,
            'recovery_received' => 0,
        ]);
    }
}
