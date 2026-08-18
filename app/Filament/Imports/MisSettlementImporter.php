<?php

namespace App\Filament\Imports;

use App\Models\CustomerSettlement;
use App\Models\MisBatch;
use App\Services\Settlement\MisSettlementImporterService;
use App\Services\Settlement\MisSettlementService;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class MisSettlementImporter extends Importer
{
    protected static ?string $model = CustomerSettlement::class;
    protected static bool $shouldPreventFormulaInjection = true;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('mis_lan_no')
                ->label('LAN')
                ->requiredMapping()
                ->rules(['required', 'string']),
            ImportColumn::make('mis_loan_type'),
            ImportColumn::make('mis_disbursal_amount')->numeric(),
            ImportColumn::make('mis_roi')->numeric(),
            ImportColumn::make('mis_cashback')->numeric(),
            ImportColumn::make('mis_subvention')->numeric(),
            ImportColumn::make('mis_docking')->numeric(),
            ImportColumn::make('mis_processing_fee')->numeric(),
            ImportColumn::make('mis_disbursal_date')->rules(['nullable', 'date']),
            ImportColumn::make('cancellation_status'),
            ImportColumn::make('cancellation_date')->rules(['nullable', 'date']),
            ImportColumn::make('cancellation_recovery')->numeric(),
            ImportColumn::make('mis_payment')->numeric(),
            ImportColumn::make('bank_commission_percentage')->numeric(),
            ImportColumn::make('bank_commission_amount')->numeric(),
            ImportColumn::make('mis_tds')->numeric(),
            ImportColumn::make('mis_gst')->numeric(),
            ImportColumn::make('actual_payable_amount')->numeric(),
        ];
    }

    public function resolveRecord(): ?CustomerSettlement
    {
        $lan = trim((string) ($this->data['mis_lan_no'] ?? ''));

        if ($lan === '') {
            throw new \RuntimeException('LAN is required.');
        }

        $record = app(MisSettlementImporterService::class)
            ->resolveByLan($lan);

        if (! $record) {
            throw new \RuntimeException(
                "LAN {$lan} was not found in Customer Settlement."
            );
        }

        return $record;
    }

    public function saveRecord(): void
    {
        $batch = MisBatch::firstOrCreate(
            ['batch_no' => 'MIS-IMPORT-' . $this->import->id],
            [
                'source' => 'excel',
                'status' => 'processing',
                'file_name' => $this->import->file_name,
                'created_by' => Auth::id(),
                'batch_date' => now()->toDateString(),
            ]
        );

        app(MisSettlementService::class)->updateFromMis(
            settlement: $this->record,
            data: $this->data,
            misBatchId: $batch->id,
            userId: Auth::id(),
            source: 'bank_mis_import',
            reason: 'Bank MIS bulk import matched by LAN.',
        );
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $batch = MisBatch::where(
            'batch_no',
            'MIS-IMPORT-' . $import->id
        )->first();

        $successful = (int) $import->successful_rows;
        $failed = (int) $import->failed_rows;

        if ($batch) {
            $batch->update([
                'status' => 'completed',
                'total_rows' => $import->total_rows,
                'processed_rows' => $import->processed_rows,
                'successful_rows' => $successful,
                'failed_rows' => $failed,
                'completed_at' => now(),
            ]);
        }

        return sprintf(
            'MIS import completed. %d rows updated successfully and %d rows failed. Please review the failed rows for details.',
            $successful,
            $failed
        );
    }
}
