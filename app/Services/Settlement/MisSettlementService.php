<?php

namespace App\Services\Settlement;

use App\Models\CustomerSettlement;
use Illuminate\Support\Facades\DB;
use App\Models\CustomerSettlementHistory;

class MisSettlementService
{
    public function updateFromMis(
        CustomerSettlement $settlement,
        array $data,
        ?int $misBatchId = null,
        ?int $userId = null
    ): CustomerSettlement {

        return DB::transaction(function () use (
            $settlement,
            $data,
            $misBatchId,
            $userId
        ) {

            $fields = [
                'mis_disbursal_amount',
                'mis_cashback',
                'mis_subvention',
                'mis_docking',
                'mis_processing_fee',
                'mis_roi',
                'mis_lan_no',
                'mis_disbursal_date',
                'bank_commission_percentage',
                'bank_commission_amount',
                'mis_tds',
                'mis_gst',
                'actual_payable_amount',
            ];

            foreach ($fields as $field) {

                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $oldValue = $settlement->{$field};
                $newValue = $data[$field];

                if ((string) $oldValue === (string) $newValue) {
                    continue;
                }

                $settlement->{$field} = $newValue;

                CustomerSettlementHistory::create([
                    'customer_settlement_id' => $settlement->id,
                    'customer_id' => $settlement->customer_id,

                    'action' => 'mis_value_updated',

                    'field_name' => $field,

                    'old_value' => $oldValue,
                    'new_value' => $newValue,

                    'source' => 'bank_mis',

                    'reason' => 'Value received from bank MIS.',

                    'performed_by' => $userId ?? auth()->id(),

                    'mis_batch_id' => $misBatchId,
                ]);
            }

            $settlement->mis_batch_id = $misBatchId;

            $settlement->updated_by = $userId ?? auth()->id();

            $settlement->save();

            app(SettlementReconciliationService::class)
                ->calculate($settlement);

            return $settlement->fresh();
        });
    }
}
