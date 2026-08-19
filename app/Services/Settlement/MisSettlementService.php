<?php

namespace App\Services\Settlement;

use App\Models\CustomerSettlement;
use App\Models\CustomerSettlementHistory;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use App\Services\Settlement\SalesImpactService;

class MisSettlementService
{
    public function updateFromMis(
        CustomerSettlement $settlement,
        array $data,
        ?int $misBatchId = null,
        ?int $userId = null,
        string $source = 'bank_mis',
        ?string $reason = null,
    ): CustomerSettlement {
        return DB::transaction(function () use ($settlement, $data, $misBatchId, $userId, $source, $reason) {
            $settlement->loadMissing('customer.employee');
            $salesImpact = app(SalesImpactService::class);
            $salesImpact->captureBefore($settlement);

            $fields = [
                'mis_lan_no', 'mis_loan_type', 'mis_disbursal_amount', 'mis_cashback',
                'mis_subvention', 'mis_docking', 'mis_processing_fee', 'mis_roi',
                'mis_disbursal_date', 'bank_commission_percentage', 'bank_commission_amount',
                'mis_tds', 'mis_gst', 'actual_payable_amount', 'mis_payment',
                'cancellation_status', 'cancellation_date', 'cancellation_recovery',
            ];

            $changed = [];

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
                $changed[$field] = [$oldValue, $newValue];

                CustomerSettlementHistory::create([
                    'customer_settlement_id' => $settlement->id,
                    'customer_id' => $settlement->customer_id,
                    'action' => 'mis_value_updated',
                    'field_name' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'source' => $source,
                    'reason' => $reason ?? 'Value received/revised from bank MIS.',
                    'performed_by' => $userId ?? auth()->id(),
                    'mis_batch_id' => $misBatchId,
                ]);
            }

            if ($misBatchId) {
                $settlement->mis_batch_id = $misBatchId;
            }

            $settlement->updated_by = $userId ?? auth()->id();

            // Any bank-data revision must be re-verified by MIS.
            if ($changed) {
                $settlement->status = 'mis_review';
                $settlement->verified_by = null;
                $settlement->verified_at = null;

                if ($settlement->customer) {
                    $settlement->customer->forceFill([
                        'account_verified' => false,
                        'account_verified_by' => null,
                        'account_verified_at' => null,
                        'incentive_calculated' => false,
                    ])->saveQuietly();
                }
            }

            $settlement->save();

            app(SettlementReconciliationService::class)->calculate($settlement);

            $settlement->refresh();
            $salesImpact->captureAfter($settlement);
            $settlement->save();
            $settlement->refresh();

            $this->notifySales($settlement, $changed);

            return $settlement;
        });
    }

    protected function notifySales(CustomerSettlement $settlement, array $changed): void
    {
        if (! $changed) {
            return;
        }

        $user = $settlement->customer?->employee?->user;

        if (! $user) {
            return;
        }

        $labels = collect(array_keys($changed))
            ->map(fn ($field) => str($field)->replace('_', ' ')->title())
            ->implode(', ');

        $achievementDifference = number_format((float) ($settlement->achievement_difference ?? 0), 2);
        $incentiveDifference = number_format((float) ($settlement->incentive_difference ?? 0), 2);

        Notification::make()
            ->title('Bank MIS Updated')
            ->body("LAN {$settlement->mis_lan_no}: {$labels}. Achievement impact: {$achievementDifference}; Incentive impact: ₹{$incentiveDifference}.")
            ->warning()
            ->sendToDatabase($user);
    }
}
