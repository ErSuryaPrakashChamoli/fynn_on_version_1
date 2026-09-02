<?php

namespace Tests\Feature;

use App\Filament\Resources\AccountVerifications\Pages\EditAccountVerification;
use App\Models\Customer;
use App\Models\CustomerSettlement;
use App\Models\CustomerSettlementHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EditAccountVerificationTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        // See SettlementReconciliationServiceStatusGuardTest: the MySQL-only
        // status ENUM widening migration is guarded to skip on sqlite, so
        // the in-memory test database is stuck with the original, narrower
        // CHECK constraint. This relaxes it for this test's connection only.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = 1');
        }
    }

    protected function tearDown(): void
    {
        CustomerSettlementHistory::flushEventListeners();

        parent::tearDown();
    }

    public function test_a_failure_after_the_mis_update_rolls_back_the_entire_verification_request(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAsAdmin();

        // Simulate a failure in the last step of the verification branch,
        // after the MIS values have already been written earlier in the
        // same request.
        CustomerSettlementHistory::creating(function (CustomerSettlementHistory $history) {
            if ($history->action === 'mis_verified') {
                throw new RuntimeException('Simulated failure mid-verification.');
            }
        });

        try {
            $this->invokeHandleRecordUpdate($customer, [
                'mis_disbursal_amount' => 2800000,
                'mis_cashback' => 50000,
                'mis_verified' => true,
                'account_remark' => 'test',
            ]);

            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure mid-verification.', $e->getMessage());
        }

        $customer->refresh();
        $settlement = CustomerSettlement::where('customer_id', $customer->id)->first();

        $this->assertFalse((bool) $customer->account_verified);

        // Everything from this request — including the sales snapshot that
        // was created earlier in the very same call — must have been
        // rolled back. Before this fix, the MIS update and sales-snapshot
        // creation committed independently of the later verification
        // failure, leaving a settlement behind with mis_disbursal_amount
        // already applied. Now the whole request is one atomic unit.
        $this->assertNull($settlement);
    }

    public function test_verification_succeeds_and_persists_everything_together(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAsAdmin();

        $this->invokeHandleRecordUpdate($customer, [
            'mis_disbursal_amount' => 2800000,
            'mis_cashback' => 50000,
            'mis_verified' => true,
            'account_remark' => 'looks good',
        ]);

        $customer->refresh();
        $settlement = CustomerSettlement::where('customer_id', $customer->id)->first();

        $this->assertTrue((bool) $customer->account_verified);
        $this->assertSame('mis_verified', $settlement->status);
        $this->assertEquals(2800000, (float) $settlement->mis_disbursal_amount);
        $this->assertDatabaseHas('customer_settlement_histories', [
            'customer_settlement_id' => $settlement->id,
            'action' => 'mis_verified',
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::factory()->create([
            'sanctioned_loan_amount' => 3000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
        ]);
    }

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin);
    }

    private function invokeHandleRecordUpdate(Customer $customer, array $data): Customer
    {
        $page = new EditAccountVerification;

        $method = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $customer, $data);
    }
}
