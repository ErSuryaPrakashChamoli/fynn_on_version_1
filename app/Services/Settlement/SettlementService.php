<?php

namespace App\Services\Settlement;

use App\Models\Customer;
use App\Models\CustomerSettlement;
use App\Models\CustomerSettlementHistory;
use Illuminate\Support\Facades\DB;

class SettlementService
{
    /**
     * Create the initial Sales snapshot.
     *
     * IMPORTANT:
     * This copies values from Customer.
     * It does NOT modify Customer.
     */
    public function createSalesSnapshot(
        Customer $customer,
        ?int $userId = null
    ): CustomerSettlement {

        return DB::transaction(function () use ($customer, $userId) {

            /*
             * Do not create another v1 if one already exists.
             */
            $existing = CustomerSettlement::query()
                ->where('customer_id', $customer->id)
                ->where('version', 1)
                ->first();

            if ($existing) {
                return $existing;
            }

            $settlement = CustomerSettlement::create([
                'settlement_no' => $this->generateSettlementNo(),

                'customer_id' => $customer->id,

                'version' => 1,

                /*
                 * SALES SNAPSHOT
                 */
                'sales_disbursal_amount' => $customer->sanctioned_loan_amount ?? 0,
                'sales_cashback' => $customer->cashback ?? 0,
                'sales_subvention' => $customer->subvention ?? 0,
                'sales_docking' => $customer->docking ?? 0,

                /*
                 * MIS is initially empty.
                 */
                'status' => 'pending',

                'created_by' => $userId ?? auth()->id(),
                'updated_by' => $userId ?? auth()->id(),
            ]);

            $this->history(
                settlement: $settlement,
                action: 'sales_snapshot_created',
                source: 'sales',
                reason: 'Initial sales values captured at settlement creation.',
                userId: $userId
            );

            return $settlement;
        });
    }

    protected function generateSettlementNo(): string
    {
        $date = now()->format('Ymd');

        $lastId = CustomerSettlement::max('id') ?? 0;

        return sprintf(
            'SET-%s-%06d',
            $date,
            $lastId + 1
        );
    }

    protected function history(
        CustomerSettlement $settlement,
        string $action,
        ?string $fieldName = null,
        mixed $oldValue = null,
        mixed $newValue = null,
        ?string $source = null,
        ?string $reason = null,
        ?int $userId = null,
        ?int $misBatchId = null,
    ): void {

        CustomerSettlementHistory::create([
            'customer_settlement_id' => $settlement->id,
            'customer_id' => $settlement->customer_id,

            'action' => $action,

            'field_name' => $fieldName,

            'old_value' => is_array($oldValue)
                ? json_encode($oldValue)
                : $oldValue,

            'new_value' => is_array($newValue)
                ? json_encode($newValue)
                : $newValue,

            'source' => $source,

            'reason' => $reason,

            'performed_by' => $userId ?? auth()->id(),

            'mis_batch_id' => $misBatchId,
        ]);
    }
}
