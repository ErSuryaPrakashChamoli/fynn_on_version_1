<?php

namespace App\Filament\Pages;

use App\Models\Employee;
use App\Services\Performance\TeamPerformanceService;
use App\Support\HierarchyHelper;
use App\Support\Performance\PerformancePeriod;
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

class TeamPerformance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Team Performance';

    protected static ?string $title = 'Team Performance';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.team-performance';

    public ?array $data = [];

    public ?Employee $teamLead = null;

    public function mount(): void
    {
        $defaultId = $this->defaultTeamLeadId();

        $this->form->fill([
            'team_lead_id' => $defaultId,
            'period_type' => PerformancePeriod::MONTHLY,
            'reference' => now()->toDateString(),
        ]);

        $this->selectTeamLead($defaultId);
    }

    protected function defaultTeamLeadId(): ?int
    {
        $user = Filament::auth()->user();

        if (! $user || $user->hasRole('Admin')) {
            return null;
        }

        return $user->employee?->id;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('team_lead_id')
                    ->label('Team Lead / Manager / Cluster Manager')
                    ->placeholder('Select a team…')
                    ->options(fn () => $this->teamLeadOptions())
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->selectTeamLead($state ? (int) $state : null)),

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

    protected function teamLeadOptions(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [];
        }

        return Employee::query()
            ->whereIn('id', HierarchyHelper::visibleEmployeeIds($user))
            ->whereIn('designation', [
                Employee::DESIGNATION_CLUSTER,
                Employee::DESIGNATION_MANAGER,
                Employee::DESIGNATION_TEAM_LEADER,
            ])
            ->orderBy('emp_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [
                $employee->id => sprintf(
                    '%s (%s)',
                    $employee->emp_name,
                    Employee::designationOptions()[$employee->designation] ?? '—'
                ),
            ])
            ->all();
    }

    public function selectTeamLead(?int $employeeId): void
    {
        $this->teamLead = $employeeId ? Employee::find($employeeId) : null;
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

    public function getAttritionProperty(): ?array
    {
        if (! $this->teamLead) {
            return null;
        }

        [$start, $end] = $this->range;

        return app(TeamPerformanceService::class)->attrition($this->teamLead, $start, $end);
    }

    public function getAttendanceProperty(): ?array
    {
        if (! $this->teamLead) {
            return null;
        }

        [$start, $end] = $this->range;

        return app(TeamPerformanceService::class)->attendance($this->teamLead, $start, $end);
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
