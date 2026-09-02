<?php

namespace App\Filament\Imports;

use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\Customer;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class AiCustomerRecordImporter extends Importer
{
    protected static ?string $model = AiCustomerRecord::class;

    public static function getColumns(): array
    {
        $columns = [
            ImportColumn::make('configuration')
                ->label('Configuration')
                ->requiredMapping()
                ->exampleHeader('Configuration')
                ->example('Personal Loan Application')
                // Consumed manually in resolveRecord(); prevent the default
                // fillRecord() behaviour from writing a non-existent
                // "configuration" attribute onto the model.
                ->fillRecordUsing(function (): void {}),

            ImportColumn::make('status')
                ->label('Status')
                ->exampleHeader('Status')
                ->example('review')
                ->rules(['nullable', 'in:pending,review,approved,rejected'])
                ->ignoreBlankState(),

            ImportColumn::make('customer_mobile_no')
                ->label('Linked Customer Mobile No')
                ->exampleHeader('Linked Customer Mobile No')
                ->example('9876543210')
                ->helperText('Optional. If a customer with this mobile number exists, the row is linked to them.')
                ->ignoreBlankState()
                ->fillRecordUsing(function (AiCustomerRecord $record, ?string $state): void {
                    if (blank($state)) {
                        return;
                    }

                    $customer = Customer::query()->where('mobile_no', trim($state))->first();

                    if ($customer) {
                        $record->customer_id = $customer->id;
                    }
                }),
        ];

        foreach (static::getMergedSchemaFields() as $field) {
            $key = (string) $field['key'];
            $type = (string) ($field['type'] ?? 'text');

            $columns[] = ImportColumn::make("field_{$key}")
                ->label($field['label'] ?? $key)
                ->exampleHeader($field['label'] ?? $key)
                ->example(static::getExampleValueForType($type))
                ->ignoreBlankState()
                // The final value for every dynamic field is assembled onto
                // the "data" JSON column inside resolveRecord(), once the
                // matching configuration/schema is known. Skip the default
                // fillRecord() behaviour here to avoid writing a
                // non-existent "field_{key}" attribute onto the model.
                ->fillRecordUsing(function (): void {});
        }

        return $columns;
    }

    /**
     * Every active configuration's columns, merged and de-duplicated by
     * key — the same set of dynamic columns shown in the Customer Data
     * listing, so the import template matches the table format.
     *
     * @return array<int, array{key: string, label?: string, type?: string}>
     */
    protected static function getMergedSchemaFields(): array
    {
        return AiDocumentSchema::query()
            ->where('is_active', true)
            ->get()
            ->flatMap(fn (AiDocumentSchema $schema) => $schema->getFieldDefinitions())
            ->filter(fn ($field) => filled($field['key'] ?? null))
            ->unique('key')
            ->values()
            ->all();
    }

    protected static function getExampleValueForType(string $type): string
    {
        return match ($type) {
            'number' => '50000',
            'decimal' => '50000.00',
            'date' => '2026-01-15',
            'mobile' => '9876543210',
            'pan' => 'ABCDE1234F',
            'email' => 'john@example.com',
            'long_text' => 'Additional notes go here',
            default => 'Sample value',
        };
    }

    public function resolveRecord(): AiCustomerRecord
    {
        $configurationName = trim((string) ($this->data['configuration'] ?? ''));

        if ($configurationName === '') {
            throw new RowImportFailedException('The Configuration column is required and must match the name of an active Document Schema.');
        }

        $schema = AiDocumentSchema::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [strtolower($configurationName)])
            ->first();

        if (! $schema) {
            throw new RowImportFailedException("No active configuration named \"{$configurationName}\" was found. Check Document Schemas for the exact name.");
        }

        $data = [];
        $missingRequiredLabels = [];

        foreach ($schema->getFieldDefinitions() as $field) {
            $key = (string) $field['key'];
            $value = $this->data["field_{$key}"] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if (blank($value)) {
                if ($field['required'] ?? false) {
                    $missingRequiredLabels[] = $field['label'] ?? $key;
                }

                continue;
            }

            $data[$key] = $value;
        }

        if ($missingRequiredLabels) {
            throw new RowImportFailedException('Missing required value(s) for: '.implode(', ', $missingRequiredLabels).'.');
        }

        $record = new AiCustomerRecord;
        $record->schema_id = $schema->id;
        $record->data = $data;
        $record->status = 'review';

        return $record;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your customer data import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import — download the failed rows file to see the reason for each.';
        }

        return $body;
    }
}
