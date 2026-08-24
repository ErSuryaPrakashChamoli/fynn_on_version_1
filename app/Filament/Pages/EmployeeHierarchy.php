<?php

namespace App\Filament\Pages;

use App\Filament\Exports\EmployeeHierarchyExporter;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use App\Support\HierarchyHelper;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use NumberFormatter;
use UnitEnum;

class EmployeeHierarchy extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Reporting Hierarchy';

    protected static ?string $title = 'Reporting Hierarchy Lookup';

    protected string $view = 'filament.pages.employee-hierarchy';

    public ?array $data = [];

    public ?Employee $employee = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label('Export Employees')
                ->exporter(EmployeeHierarchyExporter::class),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('search')
                    ->label('Employee ID or Name')
                    ->placeholder('Start typing an employee ID or name…')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => $this->optionLabel($value))
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function ($state): void {
                        $this->selectEmployee($state ? (int) $state : null);
                    }),
            ])
            ->statePath('data');
    }

    protected function searchOptions(string $search): array
    {
        return Employee::query()
            ->where(function ($query) use ($search) {
                $query->where('emp_id', 'like', "%{$search}%")
                    ->orWhere('emp_name', 'like', "%{$search}%");
            })
            ->orderBy('emp_name')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [
                $employee->id => $this->formatLabel($employee),
            ])
            ->all();
    }

    protected function optionLabel(mixed $value): ?string
    {
        $employee = Employee::find($value);

        return $employee ? $this->formatLabel($employee) : null;
    }

    protected function formatLabel(Employee $employee): string
    {
        return sprintf(
            '%s (%s) — %s',
            $employee->emp_name,
            $employee->emp_id,
            Employee::designationOptions()[$employee->designation] ?? '-'
        );
    }

    public function selectEmployee(?int $employeeId): void
    {
        $this->employee = $employeeId
            ? Employee::query()
                ->with(['superviser', 'manager', 'clusterManager'])
                ->find($employeeId)
            : null;
    }

    public function getUpwardChainProperty(): array
    {
        if (! $this->employee) {
            return [];
        }

        return array_values(array_filter([
            $this->employee->superviser,
            $this->employee->manager,
            $this->employee->clusterManager,
        ]));
    }

    public function getDownwardTreeProperty(): ?array
    {
        if (! $this->employee) {
            return null;
        }

        return $this->buildTree($this->employee);
    }

    /**
     * The employee, followed by each superior above them, immediate first.
     */
    public function getBottomToTopProperty(): array
    {
        if (! $this->employee) {
            return [];
        }

        return array_merge([$this->employee], $this->upwardChain);
    }

    protected function buildTree(Employee $employee): array
    {
        $children = HierarchyHelper::children($employee)->get();

        return [
            'employee' => $employee,
            'children' => $children
                ->map(fn (Employee $child) => $this->buildTree($child))
                ->all(),
        ];
    }

    /**
     * The employee's current-month target — their own category target for
     * a Caller, or the rolled-up target of their team for a Team Leader,
     * Manager, or Cluster Manager.
     */
    public static function targetLabel(Employee $employee): string
    {
        $target = app(AchievementCalculatorService::class)->getTarget($employee);

        $formatter = new NumberFormatter('en_IN', NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);

        return $formatter->formatCurrency($target, 'INR');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        $employee = $user->employee;

        if (! $employee) {
            return false;
        }

        return in_array($employee->designation, [
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_TEAM_LEADER,
        ]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
