<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeDependencyService;
use App\Services\EmployeeTransferService;
use App\Services\ReportingLineService;
use App\Support\HierarchyHelper;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Transfer Employee" on the Employee page and on Employee Lookup: moves
 * the employee to a new boss (EmployeeTransferService) with all their own
 * work — customers, leads, assigned leads and delegations; past months stay
 * with the old team.
 *
 * One step. Somebody with a team also picks what happens to it: the whole
 * team moves with them, part of it does (ticked one by one), or they move
 * alone. Whoever stays behind is given a new boss in the same pop-up —
 * their current boss by default — and keeps their own work.
 */
class TransferEmployeeAction
{
    public static function make(): Action
    {
        $transfers = fn (): EmployeeTransferService => app(EmployeeTransferService::class);

        $teamSize = fn (Employee $record): int => app(EmployeeDependencyService::class)->directReports($record)->count();

        return Action::make('transferEmployee')
            ->label('Transfer Employee')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (Employee $record): bool => Employee::designationRank($record->designation) > 0
                && $record->designation !== Employee::DESIGNATION_BUSINESS_HEAD
                && ! EmployeeResource::hasLeft($record))
            ->modalWidth(fn (Employee $record): Width => $teamSize($record) > 0 ? Width::ThreeExtraLarge : Width::TwoExtraLarge)
            ->modalIcon(Heroicon::OutlinedArrowPath)
            ->modalIconColor('warning')
            ->modalHeading(fn (Employee $record): string => "Transfer {$record->emp_name}")
            ->modalDescription(fn (Employee $record): string => self::takesAlongSummary($record, $teamSize($record)))
            ->fillForm(fn (Employee $record): array => [
                'current_reporting' => ($bossId = HierarchyHelper::directBossId($record))
                    ? app(ReportingLineService::class)->lineSummary($bossId)
                    : 'Nobody',
                // Those staying behind keep the boss they have today, a step up.
                'staying_team_boss' => $bossId,
                'effective_date' => today()->toDateString(),
            ])
            ->schema(fn (Employee $record): array => self::transferSchema($record, $teamSize($record)))
            ->modalSubmitActionLabel('Transfer')
            ->action(function (Employee $record, array $data, Action $action, $livewire) use ($transfers): void {
                $team = $data['team'] ?? null;
                $comingAlongIds = $transfers()->comingAlongIds($record, $team, $data['coming_along'] ?? []);

                try {
                    $transfers()->transfer(
                        $record,
                        (int) $data['reports_to'],
                        Carbon::parse($data['effective_date']),
                        $data['remarks'] ?? null,
                        Auth::user(),
                        $comingAlongIds,
                        filled($data['staying_team_boss'] ?? null) ? (int) $data['staying_team_boss'] : null,
                    );
                } catch (ValidationException $exception) {
                    EmployeeDependencyStep::refuse($exception, $action);
                }

                if ($livewire instanceof EditRecord || $livewire instanceof ViewRecord) {
                    $livewire->data['reports_to'] = HierarchyHelper::directBossId($record);
                }

                Notification::make()
                    ->title('Employee transferred successfully.')
                    ->body(match (true) {
                        $team === EmployeeTransferService::TEAM_WHOLE => "{$record->emp_name} moved with their whole team.",
                        $team === EmployeeTransferService::TEAM_PART => "{$record->emp_name} moved with ".count($comingAlongIds).' of their team; the rest stay behind under their new boss.',
                        $team === EmployeeTransferService::TEAM_NONE => "{$record->emp_name} moved alone; their team stays behind under their new boss.",
                        default => null,
                    })
                    ->success()
                    ->send();
            });
    }

    /**
     * What goes with them, shown above the form.
     */
    private static function takesAlongSummary(Employee $employee, int $teamSize): string
    {
        $summary = 'Their open customers, leads and delegations go with them. Earlier months stay with their old team.';

        if ($teamSize === 0) {
            return $summary;
        }

        $below = app(EmployeeTransferService::class)->peopleBelow($employee);

        return "{$teamSize} ".Str::plural('person', $teamSize).' '.($teamSize === 1 ? 'reports' : 'report')." straight to {$employee->emp_name} ({$below} in all below them). "
            .'Choose whether the whole team, part of it, or nobody moves with them. '.$summary;
    }

    /**
     * @return list<Component>
     */
    private static function transferSchema(Employee $employee, int $teamSize): array
    {
        $transfers = app(EmployeeTransferService::class);
        $team = fn (Get $get): ?string => $get('team');
        $picked = fn (Get $get): array => array_map('intval', (array) ($get('coming_along') ?? []));
        $someoneStaysBehind = fn (Get $get): bool => $team($get) === EmployeeTransferService::TEAM_NONE
            || ($team($get) === EmployeeTransferService::TEAM_PART && count($picked($get)) < $teamSize);

        return [
            TextInput::make('current_reporting')
                ->label('Currently Reports To')
                ->disabled()
                ->dehydrated(false),

            Select::make('reports_to')
                ->label('New Boss')
                ->options(fn (): array => app(ReportingLineService::class)->bossOptions($employee->designation, $employee->id))
                ->helperText('Anyone more senior may be chosen; the levels in between can be skipped.')
                ->required()
                ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail) use ($employee): void {
                    $problem = app(ReportingLineService::class)->bossProblem($employee->designation, (int) $value, $employee->id);

                    if ($problem !== null) {
                        $fail($problem);
                    }
                })
                ->native(false),

            ToggleButtons::make('team')
                ->label('Their team')
                ->options(EmployeeTransferService::teamChoices())
                ->icons([
                    EmployeeTransferService::TEAM_WHOLE => Heroicon::OutlinedUserGroup,
                    EmployeeTransferService::TEAM_PART => Heroicon::OutlinedUsers,
                    EmployeeTransferService::TEAM_NONE => Heroicon::OutlinedUser,
                ])
                ->colors([
                    EmployeeTransferService::TEAM_WHOLE => 'success',
                    EmployeeTransferService::TEAM_PART => 'warning',
                    EmployeeTransferService::TEAM_NONE => 'gray',
                ])
                ->inline()
                ->required()
                ->live()
                ->visible($teamSize > 0)
                ->helperText(fn (Get $get): string => match ($team($get)) {
                    EmployeeTransferService::TEAM_WHOLE => 'Everyone below them moves too, each with their own customers and leads.',
                    EmployeeTransferService::TEAM_PART => 'Tick who moves with them. Everyone below a ticked person moves too.',
                    EmployeeTransferService::TEAM_NONE => 'Their team stays behind and reports to the boss chosen below.',
                    default => 'Everyone keeps their own customers and leads, whichever you choose.',
                }),

            CheckboxList::make('coming_along')
                ->label('Moving with them')
                ->options(fn (): array => $transfers->teamChecklist($employee)['options'])
                ->descriptions(fn (): array => $transfers->teamChecklist($employee)['descriptions'])
                ->bulkToggleable()
                ->searchable($teamSize > 8)
                ->columns($teamSize > 6 ? 2 : 1)
                ->required(fn (Get $get): bool => $team($get) === EmployeeTransferService::TEAM_PART)
                ->visible(fn (Get $get): bool => $team($get) === EmployeeTransferService::TEAM_PART)
                ->live(),

            Select::make('staying_team_boss')
                ->label('Those staying behind report to')
                ->options(fn (Get $get): array => $transfers->stayingBehindBossOptions(
                    $employee,
                    $transfers->comingAlongIds($employee, $team($get), $picked($get)),
                ))
                ->required($someoneStaysBehind)
                ->visible($someoneStaysBehind)
                ->helperText('From the effective date. They keep their own customers and leads, and everyone below them stays with them.')
                ->native(false),

            DatePicker::make('effective_date')
                ->maxDate(now())
                ->required(),

            Textarea::make('remarks')
                ->rows(3),
        ];
    }
}
