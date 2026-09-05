<?php

namespace App\Filament\Pages;

use App\Enums\CommitmentStage;
use App\Models\DailyCommitment;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
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
 * The module's reporting screen: the Daily, MTD, Caller and Stage
 * summaries from section 21 of the spec, all derived from the same
 * DailyCommitmentService the dashboard uses so the numbers can never
 * disagree between the two screens.
 */
class DailyCommitmentReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Daily Commitment';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Daily Commitment Reports';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.daily-commitment.reports';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'range' => 'today',
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'role' => null,
            'month' => today()->startOfMonth()->toDateString(),
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
                    ->live(),

                DatePicker::make('month')
                    ->label('Month')
                    ->native(false)
                    ->displayFormat('M Y')
                    ->default(today()->startOfMonth())
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
            ])
            ->columns(3)
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
            ? $start->format('l, d M Y')
            : $start->format('d M Y').' – '.$end->format('d M Y');
    }

    public function getMonthProperty(): Carbon
    {
        return Carbon::parse($this->data['month'] ?? today())->startOfMonth();
    }

    public function getEmployeeIdsProperty(): Collection
    {
        return app(DailyCommitmentService::class)
            ->filterEmployeeIds(Filament::auth()->user(), $this->data ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDailyProperty(): array
    {
        $service = app(DailyCommitmentService::class);

        [$start, $end] = $this->range;

        return $service->summarise($service->rows($this->employeeIds, $start, $end));
    }

    /**
     * @return array<string, mixed>
     */
    public function getCallerReportProperty(): array
    {
        $service = app(DailyCommitmentService::class);

        $callerIds = Employee::query()
            ->whereIn('id', $this->employeeIds)
            ->where('designation', Employee::DESIGNATION_CALLER)
            ->pluck('id');

        [$start, $end] = $this->range;

        return $service->summarise($service->rows($callerIds, $start, $end));
    }

    /**
     * MTD against this module's own monthly targets, rolled up over
     * everyone in scope who has one.
     *
     * @return array<string, mixed>
     */
    public function getMtdProperty(): array
    {
        $service = app(DailyCommitmentService::class);

        $targets = MonthlyCommitmentTarget::query()
            ->whereIn('employee_id', $this->employeeIds)
            ->forMonth($this->month)
            ->get();

        $target = 0.0;
        $achieved = 0.0;
        $drr = 0.0;
        $requiredDrr = 0.0;
        $elapsed = 0;
        $remaining = 0;

        foreach ($targets as $row) {
            if ($row->stage->isCount()) {
                continue;
            }

            $position = $service->monthlyPosition($row->employee_id, $this->month);

            $target += $position['target'];
            $achieved += $position['achieved'];
            $drr += $position['drr'];
            $requiredDrr += $position['required_drr'];
            $elapsed = max($elapsed, $position['elapsed_working_days']);
            $remaining = max($remaining, $position['remaining_working_days']);
        }

        return [
            'target' => $target,
            'achieved' => $achieved,
            'pending' => max($target - $achieved, 0),
            'percentage' => $target > 0 ? round(($achieved / $target) * 100, 1) : 0.0,
            'drr' => round($drr, 2),
            'required_drr' => round($requiredDrr, 2),
            'elapsed_working_days' => $elapsed,
            'remaining_working_days' => $remaining,
            'people_with_target' => $targets->count(),
        ];
    }

    /**
     * Stage totals for the whole selected month, taken from the fulfilment
     * employees actually declared — so this is "what was claimed and
     * where it landed", never a snapshot of the standing book.
     *
     * @return array<string, array{amount: float, count: int}>
     */
    public function getMonthStageTotalsProperty(): array
    {
        $service = app(DailyCommitmentService::class);

        $commitments = DailyCommitment::query()
            ->whereIn('employee_id', $this->employeeIds)
            ->forMonth($this->month)
            ->with('entries')
            ->get();

        $totals = collect(CommitmentStage::reportable())
            ->mapWithKeys(fn (CommitmentStage $stage): array => [$stage->value => ['amount' => 0.0, 'count' => 0]])
            ->all();

        foreach ($commitments as $commitment) {
            $breakdown = $service->entryBreakdown($commitment->entries);

            foreach ($breakdown['stages'] as $stageValue => $stageTotals) {
                $totals[$stageValue]['amount'] += $stageTotals['amount'];
                $totals[$stageValue]['count'] += $stageTotals['count'];
            }

            $totals[CommitmentStage::Dropped->value]['count'] += $breakdown['dropped'];
            $totals[CommitmentStage::Rejected->value]['count'] += $breakdown['rejected'];
        }

        return $totals;
    }

    /**
     * The standing book right now, kept deliberately separate from every
     * dated number above it.
     *
     * @return array{stages: array<string, array{amount: float, count: int}>, total_amount: float, total_count: int}
     */
    public function getPipelineProperty(): array
    {
        [$start, $end] = $this->range;

        $pipeline = app(DailyCommitmentService::class)->pipeline($this->employeeIds, $start, $end);

        $totals = collect(CommitmentStage::ladder())
            ->mapWithKeys(fn (CommitmentStage $stage): array => [$stage->value => ['amount' => 0.0, 'count' => 0]])
            ->all();

        $amount = 0.0;
        $count = 0;

        foreach ($pipeline as $employee) {
            foreach ($employee['stages'] as $stageValue => $stageTotals) {
                $totals[$stageValue]['amount'] += $stageTotals['amount'];
                $totals[$stageValue]['count'] += $stageTotals['count'];
            }

            $amount += $employee['total_amount'];
            $count += $employee['total_count'];
        }

        return ['stages' => $totals, 'total_amount' => $amount, 'total_count' => $count];
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) ($user?->hasRole('Admin') || $user?->employee);
    }
}
