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
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Change Position / Target" on the Employee page: a promotion, a demotion
 * or a new target category (EmployeePositionChangeService) — the only way
 * either changes for an employee on the rolls.
 *
 * One step: nothing they hold is handed over — their customers, leads and
 * delegations stay theirs while they are on the rolls. When the position
 * changes and somebody reports to them, the same form asks who the team
 * reports to from the effective date: left empty the team stays with them,
 * which is offered only while the new position is still senior to all of
 * it (otherwise a new boss is required). The change happens on the spot, dated
 * so earlier months keep what was held then.
 */
class ChangeEmployeePositionAction
{
    public static function make(): Action
    {
        return Action::make('changePosition')
            ->label('Change Position / Target')
            ->icon(Heroicon::OutlinedArrowsUpDown)
            ->color('info')
            ->visible(fn (Employee $record): bool => ! EmployeeResource::hasLeft($record))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalIcon(Heroicon::OutlinedArrowsUpDown)
            ->modalIconColor('info')
            ->modalHeading(fn (Employee $record): string => "Change {$record->emp_name}'s position or target")
            ->modalDescription(fn (Employee $record): string => self::currentSummary($record))
            ->fillForm(fn (Employee $record): array => [
                'designation' => $record->designation,
                'reports_to' => HierarchyHelper::directBossId($record),
                'position' => $record->position,
                'category' => $record->category,
                'effective_date' => today()->toDateString(),
            ])
            ->schema(fn (Employee $record): array => self::changeSchema($record))
            ->modalSubmitActionLabel('Save change')
            ->action(function (Employee $record, array $data, Action $action, $livewire): void {
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
                        filled($data['team_boss'] ?? null) ? (int) $data['team_boss'] : null,
                    );
                } catch (ValidationException $exception) {
                    EmployeeDependencyStep::refuse($exception, $action);
                }

                $record->refresh();

                if ($livewire instanceof EditRecord || $livewire instanceof ViewRecord) {
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
        $service = app(EmployeePositionChangeService::class);

        return collect([
            'Now: '.(Employee::designationOptions()[$employee->designation] ?? '—')
                .', target category '.(TargetCategory::labelFor($employee->category) ?? 'none')
                .', reporting to '.($bossId ? app(ReportingLineService::class)->lineSummary($bossId) : 'nobody').'.',
            $service->positionSince($employee),
            $service->categorySince($employee),
            'Their customers and leads stay with them. Earlier months keep what they held before the effective date.',
        ])->filter()->implode(' ');
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
        $teamSize = $service->teamSize($employee);
        $teamSeniorRank = $service->teamSeniorRank($employee);
        $teamCanStay = fn (Get $get): bool => Employee::designationRank($newDesignation($get)) > $teamSeniorRank;

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

            Select::make('team_boss')
                ->label('Their team reports to')
                ->options(fn (): array => $service->teamBossOptions($employee))
                ->visible(fn (Get $get): bool => $teamSize > 0 && $designationChanges($get))
                ->required(fn (Get $get): bool => $teamSize > 0 && $designationChanges($get) && ! $teamCanStay($get))
                ->placeholder(fn (Get $get): string => $teamCanStay($get)
                    ? "Stays with {$employee->emp_name}"
                    : 'Choose a new boss')
                ->helperText(fn (Get $get): string => ($teamCanStay($get)
                    ? "Leave empty to keep their {$teamSize} ".Str::plural('direct report', $teamSize).' with them under the new position, or choose a new boss.'
                    : "Their {$teamSize} ".Str::plural('direct report', $teamSize).' would no longer have a more senior boss — choose who they report to.')
                    .' Either way it applies from the effective date and the team keeps its own customers and leads.')
                ->searchable()
                ->native(false),

            Select::make('position')
                ->label('Designation')
                ->options(Designation::optionsIncluding($employee->position))
                ->required()
                ->native(false),

            // Only a caller carries a category (user decision 2026-11-24);
            // a leader's target is built from their team.
            Select::make('category')
                ->label('Target Category')
                ->options(TargetCategory::callerOptionsIncluding($employee->category))
                ->visible(fn (Get $get): bool => $newDesignation($get) === Employee::DESIGNATION_CALLER)
                ->required(fn (Get $get): bool => $newDesignation($get) === Employee::DESIGNATION_CALLER)
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
