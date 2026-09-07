<?php

namespace App\Filament\Pages;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Models\Employee;
use App\Services\DailyCommitmentService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The module's own dashboard — deliberately separate from the LMS
 * target/incentive dashboard. Everything on it is derived live from the
 * existing customer journey, login sessions and this module's own
 * commitment/target tables.
 */
class DailyCommitmentDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Daily Commitment';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Daily Commitment Dashboard';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.daily-commitment.dashboard';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'range' => 'today',
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'role' => null,
            'cluster_id' => null,
            'manager_id' => null,
            'team_leader_id' => null,
            'caller_id' => null,
            'stage' => null,
            'result' => null,
            'show' => 'committed',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $service = app(DailyCommitmentService::class);
        $user = Filament::auth()->user();

        return $schema
            ->components([
                Select::make('range')
                    ->label('Period')
                    ->options(DailyCommitmentService::rangeOptions())
                    ->default('today')
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live(),

                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->visible(fn (Get $get): bool => $get('range') === 'custom')
                    ->live(),

                DatePicker::make('to')
                    ->label('To')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->afterOrEqual('from')
                    ->visible(fn (Get $get): bool => $get('range') === 'custom')
                    ->live(),

                Select::make('role')
                    ->label('Role')
                    ->options(Employee::designationOptions())
                    ->native(false)
                    ->placeholder('Everyone (by hierarchy)')
                    ->helperText('Pick a level to list only that level.')
                    ->live(),

                Select::make('cluster_id')
                    ->label('Cluster Manager')
                    ->options(fn (): array => $service->employeeOptions($user, Employee::DESIGNATION_CLUSTER))
                    ->native(false)
                    ->placeholder('All')
                    ->live(),

                Select::make('manager_id')
                    ->label('Manager')
                    ->options(fn (): array => $service->employeeOptions($user, Employee::DESIGNATION_MANAGER))
                    ->native(false)
                    ->placeholder('All')
                    ->live(),

                Select::make('team_leader_id')
                    ->label('Team Leader')
                    ->options(fn (): array => $service->employeeOptions($user, Employee::DESIGNATION_TEAM_LEADER))
                    ->native(false)
                    ->placeholder('All')
                    ->live(),

                Select::make('caller_id')
                    ->label('Caller')
                    ->options(fn (): array => $service->employeeOptions($user, Employee::DESIGNATION_CALLER))
                    ->native(false)
                    ->placeholder('All')
                    ->live(),

                Select::make('stage')
                    ->label('Stage')
                    ->options(CommitmentStage::commitableOptions())
                    ->native(false)
                    ->placeholder('All')
                    ->helperText('Matches the committed stage or the final stage reached.')
                    ->live(),

                Select::make('result')
                    ->label('Result')
                    ->options(CommitmentResult::options())
                    ->native(false)
                    ->placeholder('All')
                    ->live(),

                // Most people in a large scope have not committed on any
                // given day, so the table defaults to those who have.
                Select::make('show')
                    ->label('Show')
                    ->options([
                        'committed' => 'Gave a commitment',
                        'not_committed' => 'No commitment yet',
                        'all' => 'Everyone',
                    ])
                    ->default('committed')
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live(),
            ])
            ->columns(4)
            ->statePath('data');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function getRangeProperty(): array
    {
        return DailyCommitmentService::resolveRange(
            $this->data['range'] ?? 'today',
            $this->data['from'] ?? null,
            $this->data['to'] ?? null,
        );
    }

    public function getRangeLabelProperty(): string
    {
        [$start, $end] = $this->range;

        return $start->isSameDay($end)
            ? $start->format('d M Y')
            : $start->format('d M Y').' – '.$end->format('d M Y');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getRowsProperty(): Collection
    {
        $service = app(DailyCommitmentService::class);
        $user = Filament::auth()->user();

        [$start, $end] = $this->range;

        $rows = $service->rows(
            $service->filterEmployeeIds($user, $this->data ?? []),
            $start,
            $end,
        );

        if (filled($this->data['stage'] ?? null)) {
            $stage = CommitmentStage::from($this->data['stage']);
            $rows = $rows->filter(fn (array $row): bool => $row['stage'] === $stage
                || $row['current_stage'] === $stage);
        }

        if (filled($this->data['result'] ?? null)) {
            $result = CommitmentResult::from($this->data['result']);
            $rows = $rows->filter(fn (array $row): bool => $row['result'] === $result);
        }

        return $rows->values();
    }

    /**
     * The rows actually listed in the table. The KPI cards always read
     * the full set above, so hiding people here never changes the totals.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getTableRowsProperty(): Collection
    {
        return match ($this->data['show'] ?? 'committed') {
            'not_committed' => $this->rows->filter(fn (array $row): bool => $row['commitment'] === null)->values(),
            'all' => $this->rows,
            default => $this->rows->filter(fn (array $row): bool => $row['commitment'] !== null)->values(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummaryProperty(): array
    {
        return app(DailyCommitmentService::class)->summarise($this->rows);
    }

    /**
     * The same period figures split by hierarchy level. This is the
     * headline on the page: the sum of the Callers' commitments, of the
     * Team Leaders' and of the Managers' are three separate numbers, and
     * the combined total is only ever shown as a footer beneath them.
     *
     * @return array<int, array{designation: int, label: string, summary: array<string, mixed>}>
     */
    public function getLevelSummariesProperty(): array
    {
        return app(DailyCommitmentService::class)->summariseByLevel($this->rows);
    }

    /**
     * The whole visible group's month-to-date position, rolled up from
     * each person's own monthly target for this module.
     *
     * @return array<string, mixed>
     */
    public function getMonthlyProperty(): array
    {
        return app(DailyCommitmentService::class)->monthlyRollup($this->monthlyEmployeeIds, $this->range[1]->copy());
    }

    /**
     * Month-to-date monthly targets, split the same way as the period
     * figures above.
     *
     * @return array<int, array{designation: int, label: string, summary: array<string, mixed>}>
     */
    public function getMonthlyByLevelProperty(): array
    {
        return app(DailyCommitmentService::class)
            ->monthlyRollupByLevel($this->monthlyEmployeeIds, $this->range[1]->copy());
    }

    /**
     * @return Collection<int, int>
     */
    public function getMonthlyEmployeeIdsProperty(): Collection
    {
        return app(DailyCommitmentService::class)
            ->filterEmployeeIds(Filament::auth()->user(), $this->data ?? []);
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) ($user?->hasRole('Admin') || $user?->employee);
    }
}
