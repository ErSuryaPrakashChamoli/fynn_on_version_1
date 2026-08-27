<?php

namespace App\Filament\Pages;

use App\Models\Employee;
use App\Models\PerformanceMetricRatio;
use App\Services\Performance\EmployeePerformanceMetricsService;
use App\Services\Performance\RatioCalculator;
use App\Support\HierarchyHelper;
use App\Support\Performance\PerformancePeriod;
use App\Support\SelectedMonth;
use BackedEnum;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class EmployeePerformanceDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'My Performance';

    protected static ?string $title = 'Employee Performance';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.employee-performance-dashboard';

    public ?array $data = [];

    public ?Employee $employee = null;

    public function mount(): void
    {
        $defaultEmployeeId = Filament::auth()->user()?->employee?->id;

        $this->form->fill([
            'employee_id' => $defaultEmployeeId,
            'period_type' => PerformancePeriod::MONTHLY,
            'reference' => SelectedMonth::current()->toDateString(),
        ]);

        $this->selectEmployee($defaultEmployeeId);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('Employee')
                    ->placeholder('Select an employee…')
                    ->options(fn () => $this->employeeOptions())
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->selectEmployee($state ? (int) $state : null)),

                Select::make('period_type')
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
                    ->live()
                    ->visible(fn (Get $get) => $get('period_type') !== PerformancePeriod::CUSTOM),

                DatePicker::make('custom_from')
                    ->label('From')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->live()
                    ->visible(fn (Get $get) => $get('period_type') === PerformancePeriod::CUSTOM)
                    ->required(fn (Get $get) => $get('period_type') === PerformancePeriod::CUSTOM),

                DatePicker::make('custom_to')
                    ->label('To')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->live()
                    ->visible(fn (Get $get) => $get('period_type') === PerformancePeriod::CUSTOM)
                    ->required(fn (Get $get) => $get('period_type') === PerformancePeriod::CUSTOM)
                    ->afterOrEqual('custom_from'),
            ])
            ->statePath('data');
    }

    protected function employeeOptions(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [];
        }

        return Employee::query()
            ->whereIn('id', HierarchyHelper::visibleEmployeeIds($user))
            ->orderBy('emp_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [
                $employee->id => "{$employee->emp_name} ({$employee->emp_id})",
            ])
            ->all();
    }

    public function selectEmployee(?int $employeeId): void
    {
        $this->employee = $employeeId ? Employee::find($employeeId) : null;
    }

    public function getPeriodTypeProperty(): string
    {
        return $this->data['period_type'] ?? PerformancePeriod::MONTHLY;
    }

    public function getReferenceProperty(): Carbon
    {
        return filled($this->data['reference'] ?? null)
            ? Carbon::parse($this->data['reference'])
            : now();
    }

    public function getRangeProperty(): array
    {
        return PerformancePeriod::range(
            $this->periodType,
            $this->reference,
            filled($this->data['custom_from'] ?? null) ? Carbon::parse($this->data['custom_from']) : null,
            filled($this->data['custom_to'] ?? null) ? Carbon::parse($this->data['custom_to']) : null,
        );
    }

    public function getMetricsProperty(): ?array
    {
        if (! $this->employee) {
            return null;
        }

        [$start, $end] = $this->range;

        return app(EmployeePerformanceMetricsService::class)->rawMetrics($this->employee, $start, $end);
    }

    public function getRatiosProperty(): array
    {
        if (! $this->metrics) {
            return [];
        }

        $calculator = app(RatioCalculator::class);

        return PerformanceMetricRatio::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PerformanceMetricRatio $ratio) => [
                'ratio' => $ratio,
                'value' => $calculator->formatValue($calculator->compute($this->metrics, $ratio), $ratio),
            ])
            ->all();
    }

    /**
     * Last 6 periods of the selected cadence, oldest first — for the
     * trend strip.
     */
    public function getTrendProperty(): array
    {
        if (! $this->employee) {
            return [];
        }

        $service = app(EmployeePerformanceMetricsService::class);

        return collect(PerformancePeriod::trailing($this->periodType, $this->reference, 6))
            ->map(function (array $period) use ($service) {
                $metrics = $service->rawMetrics($this->employee, $period['start'], $period['end']);

                return [
                    'label' => $period['label'],
                    'disbursal_amount' => $metrics['disbursal_amount'],
                    'count_achievement' => $metrics['count_achievement'],
                    'login_count' => $metrics['login_count'],
                ];
            })
            ->all();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
