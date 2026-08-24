<?php

namespace App\Filament\Resources\EmployeePerformanceReports\Tables;

use App\Filament\Exports\EmployeePerformanceReportExporter;
use App\Filament\Resources\EmployeePerformanceReports\Support\ReportPeriodContext;
use App\Models\Employee;
use App\Models\PerformanceMetricRatio;
use App\Services\Performance\EmployeePerformanceMetricsService;
use App\Services\Performance\RatioCalculator;
use App\Support\Performance\PerformancePeriod;
use Carbon\Carbon;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeePerformanceReportsTable
{
    /** @var array<string, array> per-request memoization so N columns don't recompute the same employee's metrics N times */
    private static array $cache = [];

    public static function metricsFor(Employee $employee): array
    {
        [$start, $end] = ReportPeriodContext::range();

        $key = "{$employee->id}|".ReportPeriodContext::periodType()."|{$start->toDateString()}|{$end->toDateString()}";

        return self::$cache[$key] ??= app(EmployeePerformanceMetricsService::class)->rawMetrics($employee, $start, $end);
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->deferFilters(false)
            ->defaultSort('emp_name')
            ->columns(array_merge([
                TextColumn::make('emp_name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('designation')
                    ->label('Role')
                    ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? $state)
                    ->badge(),

                TextColumn::make('otp_count')
                    ->label('OTP')
                    ->state(fn (Employee $record) => self::metricsFor($record)['otp_count']),

                TextColumn::make('eligible_otp_count')
                    ->label('Eligible OTP')
                    ->state(fn (Employee $record) => self::metricsFor($record)['eligible_otp_count']),

                TextColumn::make('login_count')
                    ->label('Login')
                    ->state(fn (Employee $record) => self::metricsFor($record)['login_count']),

                TextColumn::make('approval_count')
                    ->label('Approval')
                    ->state(fn (Employee $record) => self::metricsFor($record)['approval_count']),

                TextColumn::make('disbursal_count')
                    ->label('Disbursal')
                    ->state(fn (Employee $record) => self::metricsFor($record)['disbursal_count']),

                TextColumn::make('disbursal_amount')
                    ->label('Disbursal Amount')
                    ->state(fn (Employee $record) => indianCurrencyFormat(self::metricsFor($record)['disbursal_amount']))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('count_achievement')
                    ->label('Actual Achievement')
                    ->state(fn (Employee $record) => indianCurrencyFormat(self::metricsFor($record)['count_achievement'])),

                TextColumn::make('target_amount')
                    ->label('Target')
                    ->state(fn (Employee $record) => indianCurrencyFormat(self::metricsFor($record)['target_amount']))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('present_days')
                    ->label('Present Days')
                    ->state(fn (Employee $record) => self::metricsFor($record)['present_days'].' / '.self::metricsFor($record)['working_days'])
                    ->toggleable(isToggledHiddenByDefault: true),
            ], self::ratioColumns()))
            ->filters([
                Filter::make('period')
                    ->schema([
                        Select::make('type')
                            ->label('Period')
                            ->options(PerformancePeriod::options())
                            ->default(PerformancePeriod::MONTHLY)
                            ->native(false)
                            ->live(),

                        DatePicker::make('reference')
                            ->label('Reference Date')
                            ->default(now())
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->visible(fn (Get $get) => $get('type') !== PerformancePeriod::CUSTOM),

                        DatePicker::make('custom_from')
                            ->label('From')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->visible(fn (Get $get) => $get('type') === PerformancePeriod::CUSTOM)
                            ->required(fn (Get $get) => $get('type') === PerformancePeriod::CUSTOM),

                        DatePicker::make('custom_to')
                            ->label('To')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->visible(fn (Get $get) => $get('type') === PerformancePeriod::CUSTOM)
                            ->required(fn (Get $get) => $get('type') === PerformancePeriod::CUSTOM)
                            ->afterOrEqual('custom_from'),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->query(function (Builder $query, array $data): Builder {
                        $periodType = $data['type'] ?? PerformancePeriod::MONTHLY;
                        $reference = filled($data['reference'] ?? null) ? Carbon::parse($data['reference']) : now();
                        $customStart = filled($data['custom_from'] ?? null) ? Carbon::parse($data['custom_from']) : null;
                        $customEnd = filled($data['custom_to'] ?? null) ? Carbon::parse($data['custom_to']) : null;

                        ReportPeriodContext::set($periodType, $reference, $customStart, $customEnd);

                        return $query;
                    }),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(EmployeePerformanceReportExporter::class)
                    ->label('Download Report'),
            ]);
    }

    private static function ratioColumns(): array
    {
        return PerformanceMetricRatio::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PerformanceMetricRatio $ratio) => TextColumn::make("ratio_{$ratio->id}")
                ->label($ratio->name)
                ->state(function (Employee $record) use ($ratio) {
                    $calculator = app(RatioCalculator::class);

                    return $calculator->formatValue(
                        $calculator->compute(self::metricsFor($record), $ratio),
                        $ratio
                    );
                })
                ->toggleable(isToggledHiddenByDefault: true))
            ->all();
    }
}
