<?php

namespace App\Filament\Resources\AccountVerifications\Pages;

use App\Filament\Resources\AccountVerifications\AccountVerificationResource;
use App\Models\Customer;
use App\Models\CustomerSettlementHistory;
use App\Services\Settlement\MisSettlementService;
use App\Services\Settlement\SettlementService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAccountVerification extends EditRecord
{
    protected static string $resource = AccountVerificationResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $settlement = $this->getRecord()->settlement;

        if (! $settlement) {
            $settlement = app(SettlementService::class)->createSalesSnapshot($this->getRecord());
        }

        return array_merge($data, [
            'sales_loan_type' => $settlement->sales_loan_type,
            'sales_loan_amount' => $settlement->sales_disbursal_amount,
            'sales_rate' => $settlement->sales_rate,
            'sales_cashback' => $settlement->sales_cashback,
            'sales_subvention' => $settlement->sales_subvention,
            'sales_docking' => $settlement->sales_docking,
            'mis_lan_no' => $settlement->mis_lan_no ?: $this->getRecord()->lan_no,
            'mis_loan_type' => $settlement->mis_loan_type,
            'mis_disbursal_amount' => $settlement->mis_disbursal_amount,
            'mis_roi' => $settlement->mis_roi,
            'mis_cashback' => $settlement->mis_cashback,
            'mis_subvention' => $settlement->mis_subvention,
            'mis_docking' => $settlement->mis_docking,
            'mis_processing_fee' => $settlement->mis_processing_fee,
            'mis_disbursal_date' => $settlement->mis_disbursal_date,
            'cancellation_status' => $settlement->cancellation_status,
            'cancellation_date' => $settlement->cancellation_date,
            'cancellation_recovery' => $settlement->cancellation_recovery,
            'mis_payment' => $settlement->mis_payment,
            'bank_commission_percentage' => $settlement->bank_commission_percentage,
            'bank_commission_amount' => $settlement->bank_commission_amount,
            'mis_tds' => $settlement->mis_tds,
            'mis_gst' => $settlement->mis_gst,
            'actual_payable_amount' => $settlement->actual_payable_amount,
            'variance_amount' => $settlement->variance_amount,
            'variance_cashback' => $settlement->variance_cashback,
            'variance_subvention' => $settlement->variance_subvention,
            'variance_docking' => $settlement->variance_docking,
            'variance_gst' => $settlement->variance_gst,
            'variance_tds' => $settlement->variance_tds,
            'variance_payable_amount' => $settlement->variance_payable_amount,
            'payment_difference' => $settlement->payment_difference,
            'achievement_difference' => $settlement->achievement_difference,
            'incentive_difference' => $settlement->incentive_difference,
            'account_remark' => $this->getRecord()->account_remark,
            'mis_verified' => $settlement->status === 'mis_verified',
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(auth()->user()?->hasAnyRole(['Admin', 'MIS']), 403);

        $settlement = $record->settlement ?: app(SettlementService::class)->createSalesSnapshot($record);

        $misData = collect($data)->only([
            'mis_lan_no', 'mis_loan_type', 'mis_disbursal_amount', 'mis_roi', 'mis_cashback',
            'mis_subvention', 'mis_docking', 'mis_processing_fee', 'mis_disbursal_date', 'cancellation_status',
            'cancellation_date', 'cancellation_recovery', 'mis_payment',
            'bank_commission_percentage', 'bank_commission_amount', 'mis_tds', 'mis_gst',
            'actual_payable_amount',
        ])->toArray();

        $settlement = app(MisSettlementService::class)->updateFromMis(
            settlement: $settlement,
            data: $misData,
            userId: auth()->id(),
            source: 'bank_mis',
            reason: 'MIS entered/revised bank values manually.',
        );

        $record->update([
            'account_remark' => $data['account_remark'] ?? null,
            'incentive_calculated' => false,
        ]);

        if (! empty($data['mis_verified'])) {
            $oldStatus = $settlement->status;
            $settlement->update([
                'status' => 'mis_verified',
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]);

            CustomerSettlementHistory::create([
                'customer_settlement_id' => $settlement->id,
                'customer_id' => $record->id,
                'action' => 'mis_verified',
                'old_value' => $oldStatus,
                'new_value' => 'mis_verified',
                'source' => 'mis',
                'reason' => $data['account_remark'] ?? 'MIS verification completed.',
                'performed_by' => auth()->id(),
                'mis_batch_id' => $settlement->mis_batch_id,
            ]);

            $record->update([
                'account_verified' => true,
                'account_verified_by' => auth()->id(),
                'account_verified_at' => now(),
                'incentive_calculated' => false,
            ]);
        } else {
            $settlement->update([
                'status' => 'mis_review',
                'verified_by' => null,
                'verified_at' => null,
            ]);

            $record->update([
                'account_verified' => false,
                'account_verified_by' => null,
                'account_verified_at' => null,
                'incentive_calculated' => false,
            ]);
        }

        return $record->refresh();
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
