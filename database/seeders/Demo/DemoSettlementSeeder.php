<?php

namespace Database\Seeders\Demo;

use App\Models\Customer;
use App\Models\CustomerSettlement;
use App\Models\CustomerSettlementHistory;
use App\Models\User;
use App\Services\Settlement\MisSettlementService;
use App\Services\Settlement\SettlementReconciliationService;
use App\Services\Settlement\SettlementTransactionService;
use Database\Seeders\Demo\Concerns\SeedsDemoTimeline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Moves the settlements CustomerObserver opened through the back office,
 * the way MIS and Accounts do it in the panel:
 *
 *  - MIS enters the bank's MIS figures (MisSettlementService::updateFromMis,
 *    as EditAccountVerification does) and, for most files, verifies them;
 *  - Accounts opens verified files (accounts_review, as
 *    EditCustomerSettlement does), books the commission receivable and
 *    records the payments received (SettlementTransactionService::sync).
 *
 * Only disbursed files older than a week are worked, so the current
 * fortnight's disbursals stay "pending" for the demo to act on.
 */
class DemoSettlementSeeder extends Seeder
{
    use SeedsDemoTimeline;

    public function run(): void
    {
        $mis = User::role('MIS')->firstOrFail();
        $accounts = User::role('Accounts')->firstOrFail();
        $cutoff = $this->realNow()->copy()->subDays(7)->startOfDay();

        $customers = Customer::query()
            ->where('disbursal_status', 'disbursed')
            ->whereDate('disbursal_date', '<=', $cutoff->toDateString())
            ->with('settlement')
            ->orderBy('disbursal_date')
            ->get();

        foreach ($customers as $customer) {
            $settlement = $customer->settlement;

            if ($settlement === null || fake()->boolean(15)) {
                continue;
            }

            $disbursedOn = Carbon::parse($customer->disbursal_date);
            $misDay = $disbursedOn->copy()->addDays(fake()->numberBetween(3, 6))->min($this->realNow()->copy()->startOfDay());

            $settlement = $this->replay($this->momentOn($misDay, 12, fake()->numberBetween(0, 59)), $mis, fn (): CustomerSettlement => $this->enterMisFigures($customer, $settlement, $mis));

            if (fake()->boolean(20)) {
                // Left in MIS review — a variance or a query with the bank.
                continue;
            }

            $settlement = $this->replay($this->momentOn($misDay, 16, fake()->numberBetween(0, 59)), $mis, fn (): CustomerSettlement => $this->verify($customer, $settlement, $mis));

            if ($disbursedOn->diffInDays($this->realNow()) < 15 || fake()->boolean(25)) {
                continue;
            }

            $accountsDay = $misDay->copy()->addDays(fake()->numberBetween(4, 10))->min($this->realNow()->copy()->startOfDay());

            $this->replay($this->momentOn($accountsDay, 14, fake()->numberBetween(0, 59)), $accounts, fn () => $this->settle($settlement, $accountsDay));
        }
    }

    protected function enterMisFigures(Customer $customer, CustomerSettlement $settlement, User $mis): CustomerSettlement
    {
        $salesAmount = (float) $customer->sanctioned_loan_amount;
        // Most MIS rows agree with sales; a few differ by a small amount.
        $bankAmount = fake()->boolean(80) ? $salesAmount : $salesAmount - fake()->randomElement([5000, 10000, 25000]);
        $commissionPercentage = (float) ($customer->payout_rate ?? 2.0);
        $commission = round($bankAmount * $commissionPercentage / 100, 2);

        return app(MisSettlementService::class)->updateFromMis(
            settlement: $settlement,
            data: [
                'mis_lan_no' => $customer->lan_no,
                'mis_loan_type' => $customer->loan_applied,
                'mis_disbursal_amount' => $bankAmount,
                'mis_cashback' => (float) ($customer->cashback ?? 0),
                'mis_subvention' => (float) ($customer->subvention ?? 0),
                'mis_docking' => (float) ($customer->docking ?? 0),
                'mis_processing_fee' => round($bankAmount * 0.02, 2),
                'mis_roi' => fake()->randomElement([11.49, 12.25, 12.99, 13.5, 14.25, 15.75]),
                'mis_disbursal_date' => Carbon::parse($customer->disbursal_date)->toDateString(),
                'bank_commission_percentage' => $commissionPercentage,
                'bank_commission_amount' => $commission,
            ],
            userId: $mis->id,
            source: 'bank_mis',
            reason: 'MIS entered/revised bank values manually.',
        );
    }

    /**
     * EditAccountVerification::handleRecordUpdate() with "MIS verified" ticked.
     */
    protected function verify(Customer $customer, CustomerSettlement $settlement, User $mis): CustomerSettlement
    {
        $oldStatus = $settlement->status;

        $settlement->update([
            'status' => 'mis_verified',
            'verified_by' => $mis->id,
            'verified_at' => now(),
        ]);

        CustomerSettlementHistory::query()->create([
            'customer_settlement_id' => $settlement->id,
            'customer_id' => $customer->id,
            'action' => 'mis_verified',
            'old_value' => $oldStatus,
            'new_value' => 'mis_verified',
            'source' => 'mis',
            'reason' => 'MIS verification completed.',
            'performed_by' => $mis->id,
            'mis_batch_id' => $settlement->mis_batch_id,
        ]);

        $customer->update([
            'account_verified' => true,
            'account_verified_by' => $mis->id,
            'account_verified_at' => now(),
            'incentive_calculated' => false,
            'account_remark' => 'MIS figures match the bank statement.',
        ]);

        return $settlement->refresh();
    }

    /**
     * EditCustomerSettlement::handleRecordUpdate() followed by the payments
     * Accounts records against the file.
     */
    protected function settle(CustomerSettlement $settlement, Carbon $accountsDay): void
    {
        $gross = (float) $settlement->bank_commission_amount;
        $invoice = 'INV-DEMO-'.str_pad((string) $settlement->id, 5, '0', STR_PAD_LEFT);

        foreach (['gross_payable_amount' => $gross, 'invoice_number' => $invoice] as $field => $value) {
            CustomerSettlementHistory::query()->create([
                'customer_settlement_id' => $settlement->id,
                'customer_id' => $settlement->customer_id,
                'action' => 'accounts_value_updated',
                'field_name' => $field,
                'old_value' => $settlement->{$field},
                'new_value' => $value,
                'source' => 'accounts',
                'reason' => 'Accounts settlement information updated.',
                'performed_by' => auth()->id(),
                'mis_batch_id' => $settlement->mis_batch_id,
            ]);
        }

        $settlement->update(['gross_payable_amount' => $gross, 'invoice_number' => $invoice]);

        $settlement->status = 'accounts_review';
        $settlement->save();

        CustomerSettlementHistory::query()->create([
            'customer_settlement_id' => $settlement->id,
            'customer_id' => $settlement->customer_id,
            'action' => 'accounts_review_started',
            'field_name' => 'status',
            'old_value' => 'mis_verified',
            'new_value' => 'accounts_review',
            'source' => 'accounts',
            'reason' => 'MIS verified case opened by Accounts for settlement.',
            'performed_by' => auth()->id(),
            'mis_batch_id' => $settlement->mis_batch_id,
        ]);

        $settlement = app(SettlementReconciliationService::class)->calculate($settlement);

        if (fake()->boolean(30)) {
            // Invoiced, payment not yet received.
            return;
        }

        $netPayable = (float) $settlement->net_payable_amount;
        $fullyPaid = fake()->boolean(70);
        $amount = $fullyPaid ? $netPayable : round($netPayable * 0.6, 2);

        $settlement->transactions()->create([
            'type' => 'payment',
            'amount' => $amount,
            'transaction_date' => $accountsDay->toDateString(),
            'reference_no' => 'UTR-DEMO-'.fake()->numerify('##########'),
            'remarks' => $fullyPaid ? 'Commission received in full.' : 'Part payment received from the lender.',
            'created_by' => auth()->id(),
        ]);

        $settlement->forceFill([
            'payment_received_date' => $accountsDay->toDateString(),
            'utr_number' => 'UTR-DEMO-'.fake()->numerify('##########'),
            'payment_status' => $fullyPaid ? 'paid' : 'partially_paid',
        ])->save();

        app(SettlementTransactionService::class)->sync($settlement->refresh());
    }
}
