<?php

namespace Tests\Feature;

use App\Filament\Imports\MisSettlementImporter;
use App\Models\Customer;
use App\Models\CustomerSettlement;
use App\Models\MisBatch;
use App\Models\User;
use App\Services\Settlement\MisSettlementService;
use App\Services\Settlement\SettlementService;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Verifies MisBatch's lan_not_found_rows / validation_failed_rows /
 * processing_failed_rows counters — declared in the schema and model, but
 * previously never written to anywhere — are now populated correctly, and
 * that MisSettlementImporter::getCompletedNotificationBody() reports the
 * real failed-row count instead of the always-zero value it silently
 * produced before (Import has no `failed_rows` column or camelCase-matched
 * relation, so reading ->failed_rows resolved to null).
 */
class MisSettlementImporterCounterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See SettlementReconciliationServiceStatusGuardTest: the MySQL-only
        // status ENUM widening migration is guarded to skip on sqlite, so
        // the in-memory test database is stuck with the original, narrower
        // CHECK constraint. This relaxes it for this test's connection only.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = 1');
        }
    }

    private function columnMap(): array
    {
        $names = collect(MisSettlementImporter::getColumns())
            ->map(fn ($column) => $column->getName())
            ->all();

        return array_combine($names, $names);
    }

    private function makeImport(): Import
    {
        $import = new Import;
        $import->forceFill([
            'file_name' => 'test.csv',
            'file_path' => 'imports/test.csv',
            'importer' => MisSettlementImporter::class,
            'total_rows' => 1,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'user_id' => User::factory()->create()->id,
        ])->save();

        return $import;
    }

    private function newImporter(Import $import): MisSettlementImporter
    {
        return new MisSettlementImporter($import, $this->columnMap(), []);
    }

    private function setProtected(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }

    private function callCurrentBatch(MisSettlementImporter $importer): MisBatch
    {
        $method = new ReflectionMethod($importer, 'currentBatch');
        $method->setAccessible(true);

        return $method->invoke($importer);
    }

    private function settlementWithLan(string $lan): CustomerSettlement
    {
        $customer = Customer::factory()->create([
            'lan_no' => $lan,
            'sanctioned_loan_amount' => 1000000,
        ]);

        return app(SettlementService::class)->createSalesSnapshot($customer);
    }

    private function batchFor(Import $import): ?MisBatch
    {
        return MisBatch::where('batch_no', 'MIS-IMPORT-'.$import->id)->first();
    }

    private function bindFailingMisSettlementService(string $message): void
    {
        $this->app->bind(MisSettlementService::class, fn () => new class($message) extends MisSettlementService
        {
            public function __construct(private string $message) {}

            public function updateFromMis(
                CustomerSettlement $settlement,
                array $data,
                ?int $misBatchId = null,
                ?int $userId = null,
                string $source = 'bank_mis',
                ?string $reason = null,
            ): CustomerSettlement {
                throw new RuntimeException($this->message);
            }
        });
    }

    // 1. Successful MIS row
    public function test_successful_row_does_not_increment_any_failure_counter(): void
    {
        $settlement = $this->settlementWithLan('LAN-OK-1');
        $import = $this->makeImport();
        $importer = $this->newImporter($import);

        $this->setProtected($importer, 'data', ['mis_lan_no' => 'LAN-OK-1']);
        $record = $importer->resolveRecord();
        $this->assertTrue($record->is($settlement));

        $this->setProtected($importer, 'data', [
            'mis_lan_no' => 'LAN-OK-1',
            'mis_disbursal_amount' => 1000000,
        ]);
        $importer->validateData();

        $this->setProtected($importer, 'record', $record);
        $importer->saveRecord();

        $batch = $this->batchFor($import);
        $this->assertNotNull($batch);
        $this->assertSame(0, $batch->lan_not_found_rows);
        $this->assertSame(0, $batch->validation_failed_rows);
        $this->assertSame(0, $batch->processing_failed_rows);
    }

    // 2. LAN not found
    public function test_lan_not_found_increments_only_that_counter(): void
    {
        $import = $this->makeImport();
        $importer = $this->newImporter($import);
        $this->setProtected($importer, 'data', ['mis_lan_no' => 'LAN-DOES-NOT-EXIST']);

        try {
            $importer->resolveRecord();
            $this->fail('Expected an exception.');
        } catch (Throwable) {
            // expected
        }

        $batch = $this->batchFor($import);
        $this->assertSame(1, $batch->lan_not_found_rows);
        $this->assertSame(0, $batch->validation_failed_rows);
        $this->assertSame(0, $batch->processing_failed_rows);
    }

    public function test_blank_lan_also_counts_as_lan_not_found(): void
    {
        $import = $this->makeImport();
        $importer = $this->newImporter($import);
        $this->setProtected($importer, 'data', ['mis_lan_no' => '']);

        try {
            $importer->resolveRecord();
            $this->fail('Expected an exception.');
        } catch (Throwable) {
            // expected
        }

        $this->assertSame(1, $this->batchFor($import)->lan_not_found_rows);
    }

    public function test_ambiguous_lan_also_counts_as_lan_not_found(): void
    {
        $this->settlementWithLan('LAN-AMBIGUOUS');
        $this->settlementWithLan('LAN-AMBIGUOUS');

        $import = $this->makeImport();
        $importer = $this->newImporter($import);
        $this->setProtected($importer, 'data', ['mis_lan_no' => 'LAN-AMBIGUOUS']);

        try {
            $importer->resolveRecord();
            $this->fail('Expected an exception.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, $this->batchFor($import)->lan_not_found_rows);
    }

    // 3. Validation failure
    public function test_validation_failure_increments_only_that_counter(): void
    {
        $this->settlementWithLan('LAN-BAD-DATE');
        $import = $this->makeImport();
        $importer = $this->newImporter($import);

        $this->setProtected($importer, 'data', [
            'mis_lan_no' => 'LAN-BAD-DATE',
            'mis_disbursal_date' => 'not-a-date',
        ]);

        try {
            $importer->validateData();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $batch = $this->batchFor($import);
        $this->assertSame(0, $batch->lan_not_found_rows);
        $this->assertSame(1, $batch->validation_failed_rows);
        $this->assertSame(0, $batch->processing_failed_rows);
    }

    // 4. Processing exception
    public function test_processing_failure_increments_only_that_counter(): void
    {
        $settlement = $this->settlementWithLan('LAN-PROC-FAIL');
        $this->bindFailingMisSettlementService('Simulated processing failure.');

        $import = $this->makeImport();
        $importer = $this->newImporter($import);
        $this->setProtected($importer, 'record', $settlement);
        $this->setProtected($importer, 'data', ['mis_lan_no' => 'LAN-PROC-FAIL']);

        try {
            $importer->saveRecord();
            $this->fail('Expected an exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated processing failure.', $exception->getMessage());
        }

        $batch = $this->batchFor($import);
        $this->assertSame(0, $batch->lan_not_found_rows);
        $this->assertSame(0, $batch->validation_failed_rows);
        $this->assertSame(1, $batch->processing_failed_rows);
    }

    // 5 & 6. Mixed batch containing all categories, with multiple failures
    // in the same category
    public function test_mixed_batch_counts_each_category_independently(): void
    {
        $import = $this->makeImport();

        // One success.
        $okSettlement = $this->settlementWithLan('LAN-MIX-OK');
        $importerOk = $this->newImporter($import);
        $this->setProtected($importerOk, 'data', ['mis_lan_no' => 'LAN-MIX-OK']);
        $record = $importerOk->resolveRecord();
        $this->setProtected($importerOk, 'record', $record);
        $importerOk->saveRecord();

        // Two LAN-not-found rows.
        foreach (['LAN-MIX-MISSING-1', 'LAN-MIX-MISSING-2'] as $lan) {
            $importer = $this->newImporter($import);
            $this->setProtected($importer, 'data', ['mis_lan_no' => $lan]);

            try {
                $importer->resolveRecord();
            } catch (Throwable) {
                // expected
            }
        }

        // One validation failure.
        $this->settlementWithLan('LAN-MIX-BADDATE');
        $importerBadDate = $this->newImporter($import);
        $this->setProtected($importerBadDate, 'data', [
            'mis_lan_no' => 'LAN-MIX-BADDATE',
            'mis_disbursal_date' => 'nonsense',
        ]);

        try {
            $importerBadDate->validateData();
        } catch (Throwable) {
            // expected
        }

        // One processing failure.
        $procFailSettlement = $this->settlementWithLan('LAN-MIX-PROC');
        $this->bindFailingMisSettlementService('boom');
        $importerProc = $this->newImporter($import);
        $this->setProtected($importerProc, 'record', $procFailSettlement);
        $this->setProtected($importerProc, 'data', ['mis_lan_no' => 'LAN-MIX-PROC']);

        try {
            $importerProc->saveRecord();
        } catch (Throwable) {
            // expected
        }

        $batch = $this->batchFor($import);
        $this->assertSame(2, $batch->lan_not_found_rows);
        $this->assertSame(1, $batch->validation_failed_rows);
        $this->assertSame(1, $batch->processing_failed_rows);

        // 7. Batch totals: every attempted row landed in exactly one bucket.
        $this->assertSame(
            5,
            1 + $batch->lan_not_found_rows + $batch->validation_failed_rows + $batch->processing_failed_rows
        );
    }

    // 8. "Retry"/multi-chunk safety: two importer instances processing rows
    // for the same Import (as separate queued chunks would) must share one
    // MisBatch row, not create duplicates or crash on the unique constraint.
    public function test_two_chunks_of_the_same_import_share_one_batch_row(): void
    {
        $import = $this->makeImport();

        $batchA = $this->callCurrentBatch($this->newImporter($import));
        $batchB = $this->callCurrentBatch($this->newImporter($import));

        $this->assertSame($batchA->id, $batchB->id);
        $this->assertSame(1, MisBatch::where('batch_no', 'MIS-IMPORT-'.$import->id)->count());
    }

    // The completed-notification bug: Import has no failed_rows column, so
    // reading ->failed_rows previously always resolved to null -> 0,
    // regardless of how many rows actually failed.
    public function test_completed_notification_reports_the_real_failed_row_count(): void
    {
        $import = $this->makeImport();
        $import->forceFill([
            'total_rows' => 5,
            'processed_rows' => 5,
            'successful_rows' => 3,
        ])->save();

        MisBatch::create([
            'batch_no' => 'MIS-IMPORT-'.$import->id,
            'source' => 'excel',
            'status' => 'processing',
            'batch_date' => now()->toDateString(),
        ]);

        $body = MisSettlementImporter::getCompletedNotificationBody($import);

        $this->assertStringContainsString('3 rows updated successfully', $body);
        $this->assertStringContainsString('2 rows failed', $body);

        $batch = $this->batchFor($import);
        $this->assertSame(2, $batch->failed_rows);
    }
}
