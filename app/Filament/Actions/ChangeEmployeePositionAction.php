<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Position;
use App\Models\TargetCategory;
use App\Services\EmployeePositionChangeService;
use App\Services\ReportingLineService;
use App\Support\HierarchyHelper;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Change Position / Target" on the Employee page: a promotion, a demotion
 * or a new target category (EmployeePositionChangeService) — the only way
 * either changes for an employee on the rolls.
 *
 * Two steps in one pop-up, like Transfer Employee. While anything is still
 * attached to them — their team, open customers, leads, delegations — it is
 * the EmployeeDependencyStep: listed, and handed over or cancelled right
 * there. Once nothing is left the same pop-up asks for the new position,
 * boss, target category and effective date, and the change happens on the
 * spot, dated so earlier months keep what was held then.
 */
class ChangeEmployeePositionAction
{
    public static function make(): Action
    {
        $attached = fn (Employee $record): bool => EmployeeDependencyStep::dependencies($record) !== [];

        return Action::make('changePosition')
            ->label('Change Position / Target')
            ->icon(Heroicon::OutlinedArrowsUpDown)
            ->color('info')
            ->visible(fn (Employee $record): bool => ! EmployeeResource::hasLeft($record))
            ->modalWidth(fn (Employee $record): Width => $attached($record) ? Width::ThreeExtraLarge : Width::TwoExtraLarge)
            ->modalIcon(fn (Employee $record): Heroicon => $attached($record) ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedArrowsUpDown)
            ->modalIconColor(fn (Employee $record): string => $attached($record) ? 'warning' : 'info')
            ->modalHeading(fn (Employee $record): string => $attached($record)
                ? EmployeeDependencyStep::heading($record)
                : "Change {$record->emp_name}'s position or target")
            ->modalDescription(fn (Employee $record): string => $attached($record)
                ? EmployeeDependencyStep::description($record, 'The new position and target are asked for once nothing is left.')
                : self::currentSummary($record))
            ->fillForm(fn (Employee $record, array $arguments): array => [
                'handover_date' => today()->toDateString(),
                'designation' => $record->designation,
                'reports_to' => HierarchyHelper::directBossId($record),
                'position' => $record->position,
                'category' => $record->category,
                'effective_date' => $arguments['effective_date'] ?? today()->toDateString(),
            ])
            ->schema(fn (Employee $record): array => $attached($record)
                ? EmployeeDependencyStep::schema($record)
                : self::changeSchema($record))
            ->modalSubmitActionLabel(fn (Employee $record): string => $attached($record) ? 'Hand over & continue' : 'Save change')
            ->action(function (Employee $record, array $data, Action $action, $livewire) use ($attached): void {
                if ($attached($record)) {
                    EmployeeDependencyStep::clear($record, $data, $action);

                    ReopenAction::for($action, ['effective_date' => Carbon::parse($data['handover_date'] ?? today())->toDateString()]);

                    return;
                }

                $service = app(EmployeePositionChangeService::class);
                $previousDesignation = (int) $record->designation;
                $previousCategory = filled($record->category) ? (string) $record->category : null;
                $designation = (int) $data['designation'];
                $category = filled($data['category'] ?? null) ? (string) $data['category'] : null;

                try {
                    $service->change(
                        $record,
                        $designation,
                        filled($data['reports_to'] ?? null) ? (int) $data['reports_to'] : null,
                        $category,
                        Carbon::parse($data['effective_date']),
                        $data['remarks'] ?? null,
                        Auth::user(),
                        filled($data['position'] ?? null) ? (string) $data['position'] : null,
                    );
                } catch (ValidationException $exception) {
                    EmployeeDependencyStep::refuse($exception, $action);
                }

                $record->refresh();

                if ($livewire instanceof EditRecord) {
                    $livewire->refreshFormData(['designation', 'position', 'category']);
                    $livewire->data['reports_to'] = HierarchyHelper::directBossId($record);
                }

                Notification::make()
                    ->title("{$record->emp_name} updated")
                    ->body($service->describe($previousDesignation, $designation, $previousCategory, $category)
                        .' Effective from '.Carbon::parse($data['effective_date'])->format('d M Y').'.')
                    ->success()
                    ->send();
            });
    }

    /**
     * What they hold today, shown above the change.
     */
    private static function currentSummary(Employee $employee): string
    {
        $bossId = HierarchyHelper::directBossId($employee);

        return 'Now: '.(Employee::designationOptions()[$employee->designation] ?? '—')
            .', target category '.(TargetCategory::labelFor($employee->category) ?? 'none')
            .', reporting to '.($bossId ? app(ReportingLineService::class)->lineSummary($bossId) : 'nobody')
            .'. Nothing is attached to them. Earlier months keep what they held before the effective date.';
    }

    /**
     * @return list<Component>
     */
    private static function changeSchema(Employee $employee): array
    {
        $service = app(EmployeePositionChangeService::class);
        $reportingLines = app(ReportingLineService::class);
        $currentDesignation = (int) $employee->designation;
        $currentCategory = filled($employee->category) ? (string) $employee->category : null;

        $newDesignation = fn (Get $get): ?int => filled($get('designation')) ? (int) $get('designation') : null;
        $designationChanges = fn (Get $get): bool => $newDesignation($get) !== null && $newDesignation($get) !== $currentDesignation;
        $categoryChanges = fn (Get $get): bool => (filled($get('category')) ? (string) $get('category') : null) !== $currentCategory;

        return [
            Select::make('designation')
                ->label('Position')
                ->options(Position::optionsIncluding($employee->designation))
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get) use ($reportingLines, $employee, $newDesignation): void {
                    // Drop a boss who is not senior to the new level.
                    $bossId = $get('reports_to');

                    if (filled($bossId) && $reportingLines->bossProblem($newDesignation($get), (int) $bossId, $employee->id) !== null) {
                        $set('reports_to', null);
                    }
                })
                ->helperText(fn (Get $get): string => $designationChanges($get)
                    ? $service->kindOfChange($currentDesignation, $newDesignation($get)).' from '.(Employee::designationOptions()[$currentDesignation] ?? '—').'.'
                    : 'Unchanged. Pick another position to promote or demote them.')
                ->native(false),

            Select::make('reports_to')
                ->label('Reports To')
                ->options(fn (Get $get): array => $reportingLines->bossOptions($newDesignation($get), $employee->id))
                ->visible(fn (Get $get): bool => $designationChanges($get) && $service->reportsToBoss($newDesignation($get)))
                ->required(fn (Get $get): bool => $reportingLines->requiresBoss($newDesignation($get)))
                ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($reportingLines, $employee, $get, $newDesignation): void {
                    $problem = $reportingLines->bossProblem($newDesignation($get), filled($value) ? (int) $value : null, $employee->id);

                    if ($problem !== null) {
                        $fail($problem);
                    }
                })
                ->helperText(fn (Get $get): string => filled($get('reports_to'))
                    ? 'Reporting line: '.$reportingLines->lineSummary((int) $get('reports_to'))
                    : 'Anyone more senior may be chosen; the levels in between can be skipped.')
                ->live()
                ->native(false),

            Select::make('position')
                ->label('Designation')
                ->options(Designation::optionsIncluding($employee->position))
                ->required()
                ->native(false),

            Select::make('category')
                ->label('Target Category')
                ->options(TargetCategory::optionsIncluding($employee->category))
                ->required(fn (Get $get): bool => Employee::designationRank($newDesignation($get)) > 0)
                ->helperText(fn (Get $get): string => $categoryChanges($get)
                    ? 'The new target applies from the effective date: earlier months keep the old one, and the month it falls in is split by days.'
                    : 'Unchanged.')
                ->live()
                ->native(false),

            DatePicker::make('effective_date')
                ->label('Effective date')
                ->helperText('Targets, achievement and team figures follow the change from this day.')
                ->required()
                ->maxDate(now())
                ->minDate(fn (Get $get): ?CarbonImmutable => $service->earliestEffectiveDate($employee, $designationChanges($get), $categoryChanges($get)))
                ->native(false)
                ->displayFormat('d F Y'),

            Textarea::make('remarks')
                ->label('Remarks (optional)')
                ->rows(2)
                ->maxLength(500),
        ];
    }
}
