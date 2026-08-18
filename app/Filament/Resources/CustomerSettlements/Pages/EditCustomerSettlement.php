<?php

namespace App\Filament\Resources\CustomerSettlements\Pages;

use App\Filament\Resources\CustomerSettlements\CustomerSettlementResource;
use App\Models\CustomerSettlementHistory;
use App\Services\Settlement\SettlementReconciliationService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditCustomerSettlement extends EditRecord
{
    protected static string $resource = CustomerSettlementResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(auth()->user()?->hasAnyRole(['Admin', 'Accounts']), 403);

        $trackedFields = [
            'recovery_received', 'advance_received', 'advance_adjusted',
            'gross_payable_amount', 'gst_rate', 'tds_rate', 'payment_received_amount',
            'payment_received_date', 'utr_number', 'invoice_number', 'payment_status', 'remarks',
        ];

        foreach ($trackedFields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $old = $record->{$field};
            $new = $data[$field];

            if ((string) $old === (string) $new) {
                continue;
            }

            CustomerSettlementHistory::create([
                'customer_settlement_id' => $record->id,
                'customer_id' => $record->customer_id,
                'action' => 'accounts_value_updated',
                'field_name' => $field,
                'old_value' => $old,
                'new_value' => $new,
                'source' => 'accounts',
                'reason' => 'Accounts settlement information updated.',
                'performed_by' => auth()->id(),
                'mis_batch_id' => $record->mis_batch_id,
            ]);
        }

        $record->update(Arr::only($data, $trackedFields));

        app(SettlementReconciliationService::class)->calculate($record);

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
