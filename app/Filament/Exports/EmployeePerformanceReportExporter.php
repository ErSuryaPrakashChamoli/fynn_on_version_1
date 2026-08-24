<?php

namespace App\Filament\Exports;

use App\Models\Employee;
use App\Models\PerformanceMetricRatio;
use App\Services\Performance\EmployeePerformanceMetricsService;
use App\Services\Performance\RatioCalculator;
use App\Support\Performance\PerformancePeriod;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Number;

/**
 * Export runs as a queued job, potentially in a worker process, so unlike
 * the on-screen table it can't rely on the request-scoped
 * ReportPeriodContext — the period is instead captured as export options
 * (getOptionsFormComponents()), which Filament serializes onto the Export
 * model and replays into every column's `options` parameter.
 */
class EmployeePerformanceReportExporter extends Exporter
{
    protected static ?string $model = Employee::class;

    /** @var array<string, array> */
    private static array $cache = [];

    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('type')
                ->label('Period')
                ->options(PerformancePeriod::options())
                ->default(PerformancePeriod::MONTHLY)
                ->native(false)
                ->live()
                ->required(),

            DatePicker::make('reference')
                ->label('Reference Date')
                ->default(now())
                ->native(false)
                ->visible(fn (Get $get) => $get('type') !== PerformancePeriod::CUSTOM)
                ->required(fn (Get $get) => $get('type') !== PerformancePeriod::CUSTOM),

            DatePicker::make('custom_from')
                ->label('From')
                ->native(false)
                ->visible(fn (Get $get) => $get('type') === PerformancePeriod::CUSTOM)
                ->required(fn (Get $get) => $get('type') === PerformancePeriod::CUSTOM),

            DatePicker::make('custom_to')
                ->label('To')
                ->native(false)
                ->visible(fn (Get $get) => $get('type') === PerformancePeriod::CUSTOM)
                ->required(fn (Get $get) => $get('type') === PerformancePeriod::CUSTOM)
                ->afterOrEqual('custom_from'),
        ];
    }

    public static function getColumns(): array
    {
        $columns = [
            ExportColumn::make('emp_id')->label('Employee ID'),
            ExportColumn::make('emp_name')->label('Employee'),
            ExportColumn::make('designation')
                ->label('Role')
                ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? $state),

            ExportColumn::make('otp_count')
                ->label('OTP')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['otp_count']),

            ExportColumn::make('eligible_otp_count')
                ->label('Eligible OTP')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['eligible_otp_count']),

            ExportColumn::make('login_count')
                ->label('Login')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['login_count']),

            ExportColumn::make('approval_count')
                ->label('Approval')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['approval_count']),

            ExportColumn::make('disbursal_count')
                ->label('Disbursal')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['disbursal_count']),

            ExportColumn::make('disbursal_amount')
                ->label('Disbursal Amount')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['disbursal_amount']),

            ExportColumn::make('dropped_count')
                ->label('Dropped')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['dropped_count']),

            ExportColumn::make('not_approved_count')
                ->label('Not Approved')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['not_approved_count']),

            ExportColumn::make('target_amount')
                ->label('Target')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['target_amount']),

            ExportColumn::make('actual_achievement')
                ->label('Actual Achievement')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['actual_achievement']),

            ExportColumn::make('count_achievement')
                ->label('Count Achievement')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['count_achievement']),

            ExportColumn::make('present_days')
                ->label('Present Days')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['present_days']),

            ExportColumn::make('working_days')
                ->label('Working Days')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['working_days']),

            ExportColumn::make('screen_time_hours')
                ->label('Screen Time (Hrs)')
                ->state(fn (Employee $record, array $options) => static::metricsFor($record, $options)['screen_time_hours']),
        ];

        $ratioColumns = PerformanceMetricRatio::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PerformanceMetricRatio $ratio) => ExportColumn::make("ratio_{$ratio->id}")
                ->label($ratio->name)
                ->state(function (Employee $record, array $options) use ($ratio) {
                    $calculator = app(RatioCalculator::class);
                    $metrics = static::metricsFor($record, $options);

                    return $calculator->formatValue($calculator->compute($metrics, $ratio), $ratio);
                }))
            ->all();

        return array_merge($columns, $ratioColumns);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your employee performance report has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    private static function metricsFor(Employee $employee, array $options): array
    {
        $periodType = $options['type'] ?? PerformancePeriod::MONTHLY;
        $reference = filled($options['reference'] ?? null) ? Carbon::parse($options['reference']) : now();
        $customStart = filled($options['custom_from'] ?? null) ? Carbon::parse($options['custom_from']) : null;
        $customEnd = filled($options['custom_to'] ?? null) ? Carbon::parse($options['custom_to']) : null;

        [$start, $end] = PerformancePeriod::range($periodType, $reference, $customStart, $customEnd);

        $key = "{$employee->id}|{$periodType}|{$start->toDateString()}|{$end->toDateString()}";

        return static::$cache[$key] ??= app(EmployeePerformanceMetricsService::class)->rawMetrics($employee, $start, $end);
    }
}
