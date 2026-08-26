<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSettlement;
use App\Models\CustomerSettlementTransaction;
use App\Models\User;
use App\Services\Settlement\SettlementTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two issues found here while auditing the earlier mis_verified finding:
 *
 * 1. This class declared `namespace App\Services;` while living at
 *    app/Services/Settlement/SettlementTransactionService.php. Its only
 *    consumer (TransactionsRelationManager) imports
 *    App\Services\Settlement\SettlementTransactionService — a class that
 *    did not exist. app(SettlementTransactionService::class) there threw
 *    BindingResolutionException on every use — the entire Accounts
 *    transaction-sync feature was fatally broken. Fixed by correcting the
 *    namespace to match the file's actual location.
 *
 * 2. The "never move a case out of MIS review/verification" guard (line
 *    60, pre-fix) only excluded 'mis_review', not 'mis_verified' — the
 *    same class of bug already fixed in SettlementReconciliationService.
 *    A transaction posted while a case was mis_verified, by anyone other
 *    than Accounts/Admin (whose role check on the line above is the only
 *    sanctioned way out of mis_verified), would silently overwrite the
 *    verified status.
 */
class SettlementTransactionServiceGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Accounts']);

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = 1');
        }
    }

    private function settlement(string $status): CustomerSettlement
    {
        $customer = Customer::factory()->create();

        return CustomerSettlement::create([
            'settlement_no' => 'SET-TXN-'.$status.'-'.uniqid(),
            'customer_id' => $customer->id,
            'version' => 1,
            'status' => $status,
            'net_payable_amount' => 100000,
            'gross_payable_amount' => 100000,
        ]);
    }

    private function payment(CustomerSettlement $settlement, float $amount): CustomerSettlementTransaction
    {
        return CustomerSettlementTransaction::create([
            'customer_settlement_id' => $settlement->id,
            'type' => 'payment',
            'amount' => $amount,
            'transaction_date' => now()->toDateString(),
        ]);
    }

    public function test_the_service_class_actually_resolves_from_the_container(): void
    {
        // This alone would have thrown BindingResolutionException before
        // the namespace fix.
        $this->assertInstanceOf(
            SettlementTransactionService::class,
            app(SettlementTransactionService::class)
        );
    }

    public function test_a_transaction_posted_while_mis_verified_by_a_non_accounts_user_does_not_change_status(): void
    {
        $settlement = $this->settlement('mis_verified');
        $this->payment($settlement, 100000);

        // No authenticated user at all — the role-gated transition can
        // never fire, so the guard below it is the only thing protecting
        // the status.
        $updated = app(SettlementTransactionService::class)->sync($settlement);

        $this->assertSame('mis_verified', $updated->status);
    }

    public function test_a_transaction_posted_while_mis_review_does_not_change_status(): void
    {
        $settlement = $this->settlement('mis_review');
        $this->payment($settlement, 100000);

        $updated = app(SettlementTransactionService::class)->sync($settlement);

        $this->assertSame('mis_review', $updated->status);
    }

    public function test_accounts_posting_a_transaction_on_a_verified_case_moves_it_to_accounts_review_and_beyond(): void
    {
        $accounts = User::factory()->create();
        $accounts->assignRole('Accounts');
        $this->actingAs($accounts);

        $settlement = $this->settlement('mis_verified');
        $this->payment($settlement, 100000); // fully pays net_payable_amount

        $updated = app(SettlementTransactionService::class)->sync($settlement);

        // The sanctioned transition fires (mis_verified -> accounts_review),
        // and since accounts_review is not itself protected, the same sync
        // pass refines it further based on the transaction totals.
        $this->assertSame('settled', $updated->status);
    }

    public function test_repeated_sync_calls_are_idempotent_and_do_not_double_count_transactions(): void
    {
        $accounts = User::factory()->create();
        $accounts->assignRole('Accounts');
        $this->actingAs($accounts);

        $settlement = $this->settlement('accounts_review');
        $this->payment($settlement, 40000);

        $service = app(SettlementTransactionService::class);

        $first = $service->sync($settlement);
        $this->assertSame(40000.0, (float) $first->payment_received_amount);
        $this->assertSame('partially_paid', $first->status);

        $second = $service->sync($first);
        $this->assertSame(40000.0, (float) $second->payment_received_amount);
        $this->assertSame('partially_paid', $second->status);
    }
}
