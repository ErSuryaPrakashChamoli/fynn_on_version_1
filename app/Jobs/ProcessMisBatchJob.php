<?php

namespace App\Jobs;

use App\Models\MisBatch;
use App\Models\MisBatchRow;
use App\Services\CustomerMatchingService;
use App\Services\SettlementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;


class ProcessMisBatchJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public MisBatch $batch
    ) {}

    public function handle(
        CustomerMatchingService $matchingService,
        SettlementService $settlementService
    ): void
    {
        $this->batch->update([
            'status' => 'processing',
        ]);

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Read Excel
            |--------------------------------------------------------------------------
            */

            $rows = Excel::toArray([], storage_path('app/public/' . $this->batch->file_path));

            $sheet = $rows[0] ?? [];

            $matched = 0;
            $unmatched = 0;

            foreach ($sheet as $index => $data) {

                /*
                |--------------------------------------------------------------------------
                | Skip Header
                |--------------------------------------------------------------------------
                */

                if ($index === 0) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Save Row
                |--------------------------------------------------------------------------
                */

                $misRow = MisBatchRow::create([

                    'mis_batch_id' => $this->batch->id,

                    'row_number' => $index + 1,

                    /*
                    |--------------------------------------------------------------------------
                    | CHANGE THESE INDEXES
                    |--------------------------------------------------------------------------
                    */

                    'application_no' => $data[0] ?? null,
                    'lan_no' => $data[1] ?? null,
                    'customer_name' => $data[2] ?? null,
                    'mobile_no' => $data[3] ?? null,
                    'pan_number' => $data[4] ?? null,
                    'loan_amount' => $data[5] ?? null,
                    'cashback' => $data[6] ?? null,
                    'subvention' => $data[7] ?? null,
                    'docking' => $data[8] ?? null,
                    'roi' => $data[9] ?? null,
                    'processing_fee' => $data[10] ?? null,
                    'disbursal_date' => $data[11] ?? null,

                    'raw_data' => $data,

                ]);

                /*
                |--------------------------------------------------------------------------
                | Match Customer
                |--------------------------------------------------------------------------
                */

                $customer = $matchingService->match($misRow);

                if ($customer) {

                    $matched++;

                    $settlementService->process($misRow);

                } else {

                    $unmatched++;

                }
            }

            /*
            |--------------------------------------------------------------------------
            | Update Batch
            |--------------------------------------------------------------------------
            */

            $this->batch->update([

                'status' => 'processed',

                'matched_records' => $matched,

                'unmatched_records' => $unmatched,

                'total_records' => $matched + $unmatched,

            ]);

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();

            $this->batch->update([

                'status' => 'failed',

                'remarks' => $e->getMessage(),

            ]);

            throw $e;
        }
    }
}
