<?php

namespace Database\Seeders\DemoEnvironment;

use App\Models\CustomerSettlement;
use App\Services\Settlement\SettlementReconciliationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One version-1 settlement per disbursed file, walked as far along the
 * MIS → Accounts pipeline as its age suggests: this month's files are
 * still with MIS, last month's are being paid, older ones are settled.
 * The bank-side numbers are set here and SettlementReconciliationService
 * derives variance, GST, TDS, payable and status exactly as the app does.
 */
class SettlementSeeder extends Seeder
{
    private DemoWorld $world;

    /** @var array<int, int> month offset => mis_batches.id */
    private array $batches = [];

    private int $misUserId;

    private int $accountsUserId;

    public function run(DemoWorld $world, SettlementReconciliationService $reconciliation): void
    {
        $this->world = $world;
        $this->misUserId = $world->personaUserIds['mis'];
        $this->accountsUserId = $world->personaUserIds['accounts'];

        $this->seedMisBatches();

        $customers = DB::table('customers')
            ->where('disbursal_status', 'disbursed')
            ->orderBy('disbursal_date')
            ->get();

        $sequence = 0;

        foreach ($customers as $customer) {
            $disbursedOn = Carbon::parse($customer->disbursal_date);
            $monthOffset = (int) $disbursedOn->copy()->startOfMonth()->diffInMonths($world->monthStart());
            $status = $this->statusFor($monthOffset, $disbursedOn);
            $createdAt = Carbon::parse($customer->updated_at);

            $settlement = new CustomerSettlement;
            $settlement->forceFill([
                'settlement_no' => 'SET-'.$createdAt->format('Ymd').'-'.str_pad((string) (++$sequence), 6, '0', STR_PAD_LEFT),
                'customer_id' => $customer->id,
                'version' => 1,
                'sales_disbursal_amount' => $customer->sanctioned_loan_amount,
                'sales_loan_type' => $customer->loan_applied,
                'sales_rate' => $customer->payout_rate,
                'sales_cashback' => $customer->cashback,
                'sales_subvention' => $customer->subvention,
                'sales_docking' => $customer->docking,
                'mis_lan_no' => $customer->lan_no,
                'expected_commission_percentage' => $customer->payout_rate,
                'expected_commission_amount' => round($customer->sanctioned_loan_amount * $customer->payout_rate / 100, 2),
                'status' => 'pending',
                'payment_status' => 'pending',
                'created_by' => $world->userIdFor($this->managerOf($customer)),
                'updated_by' => $world->userIdFor($this->managerOf($customer)),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();

            $history = [$this->history($settlement, 'sales_snapshot_created', null, null, null, 'sales', $settlement->created_by, $createdAt)];

            if ($status !== 'pending') {
                $history = [...$history, ...$this->applyMis($settlement, $customer, $monthOffset, $status)];
            }

            if (! in_array($status, ['pending', 'mis_review'], true)) {
                $history = [...$history, ...$this->verify($settlement, $customer)];
            }

            if (! in_array($status, ['pending', 'mis_review', 'mis_verified'], true)) {
                $history = [...$history, ...$this->account($settlement, $status, $reconciliation)];
            }

            $this->world->insert('customer_settlement_histories', $history);
        }
    }

    private function statusFor(int $monthOffset, Carbon $disbursedOn): string
    {
        // The MIS/Accounts statuses only exist on MySQL (see the
        // update_customer_settlement_statuses_for_mis_accounts migration).
        if (DB::getDriverName() !== 'mysql' || $disbursedOn->greaterThan($this->world->today->copy()->subDays(3))) {
            return 'pending';
        }

        return match ($monthOffset) {
            0 => $this->world->weighted(['pending' => 45, 'mis_review' => 30, 'mis_verified' => 25]),
            1 => $this->world->weighted(['settled' => 30, 'partially_paid' => 20, 'accounts_review' => 15, 'variance' => 10, 'payment_pending' => 10, 'mis_verified' => 8, 'hold' => 4, 'recovery_pending' => 3]),
            default => $this->world->weighted(['settled' => 72, 'partially_paid' => 10, 'recovery_pending' => 8, 'variance' => 6, 'hold' => 4]),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function applyMis(CustomerSettlement $settlement, object $customer, int $monthOffset, string $status): array
    {
        $amount = (float) $customer->sanctioned_loan_amount;
        $rate = (float) $customer->payout_rate;

        // A variance file is one where the bank's MIS disagrees with sales.
        if ($status === 'variance') {
            $amount -= $this->world->amount(10000, 40000);
            $rate = max(1.0, $rate - 0.25);
        }

        $commission = round($amount * $rate / 100, 2);
        $gst = round($commission * 0.18, 2);
        $tds = round($commission * 0.02, 2);
        $at = Carbon::parse($customer->disbursal_date)->addDays(mt_rand(2, 6))->setTime(mt_rand(10, 17), mt_rand(0, 59))->min($this->world->now);

        $settlement->forceFill([
            'mis_batch_id' => $this->batches[min($monthOffset, 2)] ?? null,
            'mis_disbursal_amount' => $amount,
            'mis_loan_type' => $customer->loan_applied,
            'mis_cashback' => $customer->cashback,
            'mis_subvention' => $customer->subvention,
            'mis_docking' => 0,
            'mis_processing_fee' => round($amount * $this->world->pick([1.0, 1.5, 2.0, 2.5]) / 100, 2),
            'mis_roi' => $this->world->pick([10.49, 10.99, 11.25, 11.75, 12.5, 13.99, 14.5, 15.99]),
            'mis_disbursal_date' => $customer->disbursal_date,
            'bank_commission_percentage' => $rate,
            'bank_commission_amount' => $commission,
            'company_commission' => $commission,
            'mis_gst' => $gst,
            'mis_tds' => $tds,
            'actual_payable_amount' => round($commission + $gst - $tds, 2),
            'mis_payment' => round($commission + $gst - $tds, 2),
            'variance_commission' => round($commission - (float) $settlement->expected_commission_amount, 2),
            'status' => 'mis_review',
            'updated_by' => $this->misUserId,
            'updated_at' => $at,
        ])->saveQuietly();

        return [
            $this->history($settlement, 'mis_value_updated', 'mis_disbursal_amount', null, (string) $amount, 'bank_mis_import', $this->misUserId, $at),
            $this->history($settlement, 'mis_value_updated', 'bank_commission_percentage', null, (string) $rate, 'bank_mis_import', $this->misUserId, $at),
            $this->history($settlement, 'mis_value_updated', 'mis_roi', null, (string) $settlement->mis_roi, 'bank_mis_import', $this->misUserId, $at),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function verify(CustomerSettlement $settlement, object $customer): array
    {
        $at = Carbon::parse($settlement->updated_at)->addDays(mt_rand(1, 3))->setTime(mt_rand(10, 18), mt_rand(0, 59))->min($this->world->now);

        $settlement->forceFill([
            'status' => 'mis_verified',
            'verified_by' => $this->misUserId,
            'verified_at' => $at,
            'updated_at' => $at,
        ])->saveQuietly();

        DB::table('customers')->where('id', $customer->id)->update([
            'account_verified' => true,
            'account_verified_by' => $this->misUserId,
            'account_verified_at' => $at,
            'account_remark' => $this->world->pick(['MIS matched with bank statement.', 'Verified against bank MIS.', 'LAN and amount verified.']),
        ]);

        return [$this->history($settlement, 'mis_verified', 'status', 'mis_review', 'mis_verified', 'mis', $this->misUserId, $at)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function account(CustomerSettlement $settlement, string $status, SettlementReconciliationService $reconciliation): array
    {
        $at = Carbon::parse($settlement->verified_at)->addDays(mt_rand(2, 8))->setTime(mt_rand(10, 18), mt_rand(0, 59))->min($this->world->now);
        $gross = (float) $settlement->bank_commission_amount;
        $net = round($gross * 1.16, 2);

        $received = match ($status) {
            'settled', 'recovery_pending' => $net,
            'partially_paid' => round($net * $this->world->pick([0.4, 0.5, 0.6, 0.75]), 2),
            default => 0,
        };

        $settlement->forceFill([
            'status' => 'accounts_review',
            'gross_payable_amount' => $gross,
            'gst_rate' => 18,
            'tds_rate' => 2,
            'payment_received_amount' => $received,
            'payment_received_date' => $received > 0 ? $at->toDateString() : null,
            'payment_status' => match ($status) {
                'settled', 'recovery_pending' => 'paid',
                'partially_paid' => 'partially_paid',
                'hold' => 'hold',
                default => 'pending',
            },
            'utr_number' => $received > 0 ? 'UTR'.mt_rand(100000000, 999999999).mt_rand(100, 999) : null,
            'invoice_number' => 'INV/'.$at->format('Y').'/'.str_pad((string) $settlement->id, 5, '0', STR_PAD_LEFT),
            'cancellation_status' => $status === 'recovery_pending' ? 'cancelled' : null,
            'cancellation_date' => $status === 'recovery_pending' ? $at->toDateString() : null,
            'cancellation_recovery' => $status === 'recovery_pending' ? $gross : 0,
            'recovery_received' => 0,
            'remarks' => match ($status) {
                'hold' => 'On hold — bank has raised a query on this file.',
                'variance' => 'Commission short-paid against sales rate; raised with bank.',
                'recovery_pending' => 'Loan cancelled by customer within cooling-off; recovery due.',
                default => null,
            },
            'updated_by' => $this->accountsUserId,
            'updated_at' => $at,
        ]);

        $reconciliation->calculate($settlement);

        if (in_array($status, ['payment_pending', 'hold'], true)) {
            $settlement->forceFill(['status' => $status])->saveQuietly();
        }

        if ($received > 0) {
            DB::table('customer_settlement_transactions')->insert([
                'customer_settlement_id' => $settlement->id,
                'type' => 'payment',
                'amount' => $received,
                'transaction_date' => $at->toDateString(),
                'reference_no' => $settlement->utr_number,
                'remarks' => 'Commission received from bank.',
                'created_by' => $this->accountsUserId,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        DB::table('customers')->where('id', $settlement->customer_id)->update(['incentive_calculated' => $status === 'settled']);

        return [
            $this->history($settlement, 'accounts_review_started', 'status', 'mis_verified', 'accounts_review', 'accounts', $this->accountsUserId, $at),
            $this->history($settlement, 'accounts_value_updated', 'payment_status', 'pending', $settlement->payment_status, 'accounts', $this->accountsUserId, $at),
        ];
    }

    private function seedMisBatches(): void
    {
        foreach ([2, 1, 0] as $offset) {
            $batchDate = $this->world->monthStart($offset)->addDays(min(24, max(1, $this->world->today->day - 2)));

            if ($offset > 0) {
                $batchDate = $this->world->monthStart($offset)->addDays(mt_rand(20, 26));
            }

            $total = mt_rand(140, 260);
            $failed = mt_rand(2, 9);

            $this->batches[$offset] = DB::table('mis_batches')->insertGetId([
                'batch_no' => 'MIS-'.$batchDate->format('Ym').'-'.str_pad((string) (3 - $offset), 2, '0', STR_PAD_LEFT),
                'batch_date' => $batchDate->toDateString(),
                'file_name' => 'bank-mis-'.$batchDate->format('M-Y').'.xlsx',
                'source' => 'excel',
                'status' => 'completed',
                'total_rows' => $total,
                'processed_rows' => $total,
                'successful_rows' => $total - $failed,
                'failed_rows' => $failed,
                'unmatched_rows' => mt_rand(0, 3),
                'lan_not_found_rows' => mt_rand(0, $failed),
                'validation_failed_rows' => mt_rand(0, 2),
                'processing_failed_rows' => 0,
                'error_summary' => json_encode([]),
                'created_by' => $this->misUserId,
                'completed_at' => $batchDate->copy()->setTime(15, 30),
                'created_at' => $batchDate->copy()->setTime(15, 20),
                'updated_at' => $batchDate->copy()->setTime(15, 30),
            ]);
        }
    }

    private function managerOf(object $customer): ?int
    {
        return $this->world->employees->firstWhere('id', $customer->employee_id)?->manager_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function history(CustomerSettlement $settlement, string $action, ?string $field, ?string $old, ?string $new, string $source, ?int $userId, Carbon $at): array
    {
        return [
            'customer_settlement_id' => $settlement->id,
            'customer_id' => $settlement->customer_id,
            'action' => $action,
            'field_name' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'source' => $source,
            'reason' => null,
            'performed_by' => $userId,
            'mis_batch_id' => $source === 'bank_mis_import' ? $settlement->mis_batch_id : null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }
}
