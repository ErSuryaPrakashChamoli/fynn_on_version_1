<?php

namespace Tests\Feature;

use App\Filament\Imports\AiCustomerRecordImporter;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\Customer;
use App\Models\User;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiCustomerRecordImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_a_row_into_the_matching_configuration(): void
    {
        $schema = AiDocumentSchema::create([
            'name' => 'Personal Loan Application',
            'is_active' => true,
            'fields' => [
                ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text', 'required' => true],
                ['key' => 'loan_amount', 'label' => 'Loan Amount', 'type' => 'number', 'required' => false],
            ],
        ]);

        $customer = Customer::factory()->create(['mobile_no' => '9876543210']);

        $importer = $this->makeImporter();

        $importer([
            'configuration' => 'Personal Loan Application',
            'status' => 'approved',
            'customer_mobile_no' => '9876543210',
            'field_customer_name' => 'Jane Doe',
            'field_loan_amount' => '50000',
        ]);

        $record = AiCustomerRecord::sole();

        $this->assertSame($schema->id, $record->schema_id);
        $this->assertSame($customer->id, $record->customer_id);
        $this->assertSame('approved', $record->status);
        $this->assertSame('Jane Doe', $record->data['customer_name']);
        $this->assertSame('50000', $record->data['loan_amount']);
    }

    public function test_it_fails_the_row_with_a_reason_when_the_configuration_is_unknown(): void
    {
        $importer = $this->makeImporter();

        $this->expectException(RowImportFailedException::class);
        $this->expectExceptionMessage('No active configuration named "Does Not Exist" was found. Check Document Schemas for the exact name.');

        $importer([
            'configuration' => 'Does Not Exist',
            'field_customer_name' => 'Jane Doe',
        ]);
    }

    public function test_it_fails_the_row_with_a_reason_when_a_required_field_is_missing(): void
    {
        AiDocumentSchema::create([
            'name' => 'Personal Loan Application',
            'is_active' => true,
            'fields' => [
                ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text', 'required' => true],
            ],
        ]);

        $importer = $this->makeImporter();

        $this->expectException(RowImportFailedException::class);
        $this->expectExceptionMessage('Missing required value(s) for: Customer Name.');

        $importer([
            'configuration' => 'Personal Loan Application',
        ]);
    }

    private function makeImporter(): AiCustomerRecordImporter
    {
        $user = User::factory()->create();

        $import = Import::create([
            'file_name' => 'customer-data.csv',
            'file_path' => 'imports/customer-data.csv',
            'importer' => AiCustomerRecordImporter::class,
            'total_rows' => 1,
            'user_id' => $user->id,
        ]);

        $columnMap = collect(AiCustomerRecordImporter::getColumns())
            ->mapWithKeys(fn ($column) => [$column->getName() => $column->getName()])
            ->all();

        return new AiCustomerRecordImporter($import, $columnMap, []);
    }
}
