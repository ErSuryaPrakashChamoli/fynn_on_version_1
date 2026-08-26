<?php

namespace App\Filament\Imports;

use App\Models\CustomerSettlement;
use App\Models\MisBatch;
use App\Services\Settlement\MisSettlementImporterService;
use App\Services\Settlement\MisSettlementService;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Throwable;

class MisSettlementImporter extends Importer
{
    protected static ?string $model = CustomerSettlement::class;

    protected static bool $shouldPreventFormulaInjection = true;

    /**
     * Cached per row-chunk job instance, so every row in the same chunk
     * reuses one lookup instead of re-querying per row.
     */
    protected ?MisBatch $misBatch = null;

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

    /**
     * LAN resolution stage. Any failure here — a blank LAN, no matching
     * settlement, or an ambiguous LAN matching more than one settlement —
     * is a "LAN not found" outcome, counted on the batch before the
     * exception propagates (Filament still records the row as failed via
     * its own generic mechanism).
     */
    public function resolveRecord(): ?CustomerSettlement
    {
        try {
            $lan = trim((string) ($this->data['mis_lan_no'] ?? ''));

            if ($lan === '') {
                throw new \RuntimeException('LAN is required.');
            }

            return app(MisSettlementImporterService::class)->resolveByLan($lan);
        } catch (Throwable $exception) {
            $this->currentBatch()->increment('lan_not_found_rows');

            throw $exception;
        }
    }

    /**
     * Field-level validation stage (ImportColumn::rules()), which only
     * runs once resolveRecord() has already found a matching settlement.
     * A failure here is a genuine "row exists but the data is invalid"
     * outcome, distinct from a LAN lookup failure.
     */
    public function validateData(): void
    {
        try {
            parent::validateData();
        } catch (Throwable $exception) {
            $this->currentBatch()->increment('validation_failed_rows');

            throw $exception;
        }
    }

    /**
     * Applying the validated MIS values to the settlement. A failure here
     * happened after the row was found and validated, so it's a genuine
     * processing failure, not a data problem.
     */
    public function saveRecord(): void
    {
        try {
            app(MisSettlementService::class)->updateFromMis(
                settlement: $this->record,
                data: $this->data,
                misBatchId: $this->currentBatch()->id,
                userId: Auth::id(),
                source: 'bank_mis_import',
                reason: 'Bank MIS bulk import matched by LAN.',
            );
        } catch (Throwable $exception) {
            $this->currentBatch()->increment('processing_failed_rows');

            throw $exception;
        }
    }

    /**
     * Resolves (and memoizes, per chunk job instance) the MisBatch row for
     * this import, creating it on first use regardless of which lifecycle
     * stage needs it first. firstOrCreate() alone is not safe against two
     * chunks of the same import racing to create the row concurrently —
     * on a duplicate-key conflict, re-fetch the row the other chunk just
     * created instead of failing.
     */
    protected function currentBatch(): MisBatch
    {
        if ($this->misBatch) {
            return $this->misBatch;
        }

        $batchNo = 'MIS-IMPORT-'.$this->import->id;

        $batch = MisBatch::where('batch_no', $batchNo)->first();

        if (! $batch) {
            try {
                $batch = MisBatch::create([
                    'batch_no' => $batchNo,
                    'source' => 'excel',
                    'status' => 'processing',
                    'file_name' => $this->import->file_name,
                    'created_by' => Auth::id(),
                    'batch_date' => now()->toDateString(),
                ]);
            } catch (QueryException) {
                $batch = MisBatch::where('batch_no', $batchNo)->firstOrFail();
            }
        }

        return $this->misBatch = $batch;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $batch = MisBatch::where(
            'batch_no',
            'MIS-IMPORT-'.$import->id
        )->first();

        $successful = (int) $import->successful_rows;

        // Import::$failed_rows is not a real column — Import only stores
        // total_rows/successful_rows and derives the failed count via
        // getFailedRowsCount(). Reading ->failed_rows directly resolves to
        // null (there is no failedRows() relation match either, since that
        // relation method is camelCase and Eloquent's magic property
        // resolution does not convert snake_case to camelCase for
        // relations), so this previously always reported 0 failures
        // regardless of how many rows actually failed.
        $failed = $import->getFailedRowsCount();

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
