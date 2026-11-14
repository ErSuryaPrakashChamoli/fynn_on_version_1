<?php

namespace App\Filament\Exports;

use App\Filament\Actions\DataDownloadApproval;
use App\Filament\Resources\AiCustomerRecords\Tables\AiCustomerRecordsTable;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\FollowUp;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Throwable;

/**
 * Customer Data with who holds each record, since when, and where the
 * chase stands (latest follow-up status and remarks), so the Admin can
 * check every hand-over in one sheet. Converted records keep their
 * assignment (it moves to the customer file on conversion). Lead data, so
 * the export needs the owner's authenticator code (DataDownloadApproval).
 */
class AiCustomerRecordExporter extends Exporter
{
    protected static ?string $model = AiCustomerRecord::class;

    private const DATE_TIME_FORMAT = 'd M Y h:i A';

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('S. No.'),
            ExportColumn::make('schema.name')->label('Configuration'),
            ExportColumn::make('customer_name')
                ->label('Customer Name')
                ->state(fn (AiCustomerRecord $record): ?string => $record->prospectValue('customer_name') ?? $record->customer?->customer_name),
            ExportColumn::make('mobile_number')
                ->label('Mobile Number')
                ->state(fn (AiCustomerRecord $record): ?string => $record->prospectValue('mobile_number') ?? $record->customer?->mobile_no),
            ExportColumn::make('record_status')
                ->label('Status')
                ->state(fn (AiCustomerRecord $record): string => self::recordStatus($record)),
            ExportColumn::make('assigned_to')
                ->label('Assigned To')
                ->state(fn (AiCustomerRecord $record): ?string => $record->currentAssignment()?->employee?->emp_name),
            ExportColumn::make('assigned_to_emp_id')
                ->label('Assigned To Emp ID')
                ->state(fn (AiCustomerRecord $record): ?string => $record->currentAssignment()?->employee?->emp_id),
            ExportColumn::make('assigned_by')
                ->label('Assigned By')
                ->state(fn (AiCustomerRecord $record): ?string => $record->currentAssignment()?->assignedBy?->emp_name),
            ExportColumn::make('assigned_on')
                ->label('Assigned On')
                ->state(fn (AiCustomerRecord $record): ?string => $record->currentAssignment()?->created_at?->format(self::DATE_TIME_FORMAT)),
            ExportColumn::make('lead_status')
                ->label('Lead Status')
                ->state(fn (AiCustomerRecord $record): ?string => $record->currentAssignment()
                    ? ($record->latest_follow_up_status ?? 'Pending')
                    : $record->latest_follow_up_status),
            ExportColumn::make('latest_remarks')
                ->label('Remarks')
                ->state(fn (AiCustomerRecord $record): ?string => $record->latest_follow_up_remarks),
            ExportColumn::make('last_follow_up_on')
                ->label('Last Follow-Up On')
                ->state(fn (AiCustomerRecord $record): ?string => self::formatDateTime($record->latest_follow_up_at)),
            ExportColumn::make('next_follow_up_on')
                ->label('Next Follow-Up')
                ->state(fn (AiCustomerRecord $record): ?string => self::formatDateTime($record->latest_next_follow_up_date)),
            ExportColumn::make('converted_on')
                ->label('Converted On')
                ->state(fn (AiCustomerRecord $record): ?string => $record->currentAssignment()?->converted_at?->format(self::DATE_TIME_FORMAT)),
            ExportColumn::make('created_at')
                ->label('Uploaded On')
                ->formatStateUsing(fn (mixed $state): ?string => self::formatDateTime($state)),
            ...self::templateFieldColumns(),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            DataDownloadApproval::codeField(),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your customer data export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    /**
     * Loads every assignment (own and converted) and reads the latest
     * follow-up per record as subqueries, so the sheet is built without a
     * query per row. Follow-ups move to the customer file on conversion.
     */
    public static function modifyQuery(Builder $query): Builder
    {
        return $query
            ->with([
                'schema',
                'customer',
                'latestAssignment.employee',
                'latestAssignment.assignedBy',
                'convertedAssignment.employee',
                'convertedAssignment.assignedBy',
            ])
            ->addSelect([
                'latest_follow_up_status' => self::latestFollowUpValueQuery('status'),
                'latest_follow_up_remarks' => self::latestFollowUpValueQuery('remarks'),
                'latest_follow_up_at' => self::latestFollowUpValueQuery('created_at'),
                'latest_next_follow_up_date' => self::latestFollowUpValueQuery('next_follow_up_date'),
            ]);
    }

    /**
     * @return Builder<FollowUp>
     */
    private static function latestFollowUpValueQuery(string $column): Builder
    {
        return FollowUp::query()
            ->select("follow_ups.{$column}")
            ->where(function (Builder $query): void {
                $query->whereColumn('follow_ups.ai_customer_record_id', 'ai_customer_records.id')
                    ->orWhere(function (Builder $query): void {
                        $query->whereNotNull('ai_customer_records.customer_id')
                            ->whereColumn('follow_ups.customer_id', 'ai_customer_records.customer_id');
                    });
            })
            ->orderByDesc('follow_ups.created_at')
            ->orderByDesc('follow_ups.id')
            ->limit(1);
    }

    /**
     * Mirrors the listing badge, plus "Converted" once the record became a
     * customer file.
     */
    private static function recordStatus(AiCustomerRecord $record): string
    {
        return match (true) {
            filled($record->customer_id) => 'Converted',
            $record->latestAssignment !== null => Str::headline(AiCustomerRecordsTable::STATUS_ASSIGNED),
            default => Str::headline((string) $record->status),
        };
    }

    private static function formatDateTime(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->format(self::DATE_TIME_FORMAT) : null;
    }

    /**
     * Each upload template's own fields, off by default, for anyone who
     * wants the full row next to the hand-over details.
     *
     * @return list<ExportColumn>
     */
    private static function templateFieldColumns(): array
    {
        try {
            $fields = AiDocumentSchema::query()->get()
                ->flatMap(fn (AiDocumentSchema $schema): array => $schema->getFieldDefinitions())
                ->filter(fn (mixed $field): bool => is_array($field) && filled($field['key'] ?? null))
                ->unique(fn (array $field): string => self::templateFieldColumnName((string) $field['key']))
                ->values();
        } catch (Throwable) {
            return [];
        }

        return $fields
            ->map(fn (array $field): ExportColumn => ExportColumn::make(self::templateFieldColumnName((string) $field['key']))
                ->label((string) ($field['label'] ?? $field['key']))
                ->state(fn (AiCustomerRecord $record): mixed => data_get($record->data, (string) $field['key']))
                ->enabledByDefault(false))
            ->all();
    }

    private static function templateFieldColumnName(string $key): string
    {
        return 'data_field_'.Str::slug($key, '_');
    }
}
