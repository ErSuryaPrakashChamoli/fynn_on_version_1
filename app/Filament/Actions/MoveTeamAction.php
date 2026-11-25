<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeDependencyService;
use App\Services\EmployeeTransferService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
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
 * "Move Team" on the Employee pages and Employee Lookup: the employee stays
 * where they are while their whole team, or the people ticked, move to
 * another boss (EmployeeTransferService::moveTeam()) — any level: a Team
 * Leader's callers, a Manager's Team Leaders, a Cluster Manager's Managers.
 * Everybody who moves takes everyone below them and all their own work;
 * earlier months stay with the old boss.
 */
class MoveTeamAction
{
    public static function make(): Action
    {
        $teamSize = fn (Employee $record): int => app(EmployeeDependencyService::class)->directReports($record)->count();

        return Action::make('moveTeam')
            ->label('Move Team')
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('warning')
            ->visible(fn (Employee $record): bool => ! EmployeeResource::hasLeft($record) && $teamSize($record) > 0)
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalIcon(Heroicon::OutlinedUserGroup)
            ->modalIconColor('warning')
            ->modalHeading(fn (Employee $record): string => "Move {$record->emp_name}'s team")
            ->modalDescription(fn (Employee $record): string => "{$record->emp_name} stays where they are. Whoever moves takes everyone below them and all their own customers and leads; earlier months stay with {$record->emp_name}.")
            ->fillForm(fn (): array => [
                'scope' => EmployeeTransferService::TEAM_WHOLE,
                'effective_date' => today()->toDateString(),
            ])
            ->schema(fn (Employee $record): array => self::schema($record, $teamSize($record)))
            ->modalSubmitActionLabel('Move')
            ->action(function (Employee $record, array $data, Action $action): void {
                $whole = ($data['scope'] ?? null) === EmployeeTransferService::TEAM_WHOLE;

                try {
                    $log = app(EmployeeTransferService::class)->moveTeam(
                        $record,
                        $whole ? null : array_map('intval', (array) ($data['member_ids'] ?? [])),
                        (int) $data['target_id'],
                        Carbon::parse($data['effective_date']),
                        $data['remarks'] ?? null,
                        (int) Auth::id(),
                    );
                } catch (ValidationException $exception) {
                    EmployeeDependencyStep::refuse($exception, $action);
                }

                $target = Employee::query()->find((int) $data['target_id']);

                Notification::make()
                    ->title('Team moved')
                    ->body(($whole ? "{$record->emp_name}'s whole team" : 'The people ticked')
                        ." now report to {$target?->emp_name} — {$log->affected_count} ".Str::plural('employee', $log->affected_count).' in all.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return list<Component>
     */
    private static function schema(Employee $employee, int $teamSize): array
    {
        $transfers = app(EmployeeTransferService::class);
        $memberIds = fn (Get $get): ?array => $get('scope') === EmployeeTransferService::TEAM_PART
            ? array_map('intval', (array) ($get('member_ids') ?? []))
            : null;

        return [
            ToggleButtons::make('scope')
                ->label('Who moves')
                ->options([
                    EmployeeTransferService::TEAM_WHOLE => "Whole team ({$teamSize})",
                    EmployeeTransferService::TEAM_PART => 'Only the people I tick',
                ])
                ->icons([
                    EmployeeTransferService::TEAM_WHOLE => Heroicon::OutlinedUserGroup,
                    EmployeeTransferService::TEAM_PART => Heroicon::OutlinedUsers,
                ])
                ->colors([
                    EmployeeTransferService::TEAM_WHOLE => 'success',
                    EmployeeTransferService::TEAM_PART => 'warning',
                ])
                ->inline()
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('target_id', null)),

            CheckboxList::make('member_ids')
                ->label('Moving')
                ->options(fn (): array => $transfers->teamChecklist($employee)['options'])
                ->descriptions(fn (): array => $transfers->teamChecklist($employee)['descriptions'])
                ->bulkToggleable()
                ->searchable($teamSize > 8)
                ->columns($teamSize > 6 ? 2 : 1)
                ->required(fn (Get $get): bool => $get('scope') === EmployeeTransferService::TEAM_PART)
                ->visible(fn (Get $get): bool => $get('scope') === EmployeeTransferService::TEAM_PART)
                ->live(),

            Select::make('target_id')
                ->label('Move them to')
                ->options(fn (Get $get): array => $transfers->moveTeamTargetOptions($employee, $memberIds($get)))
                ->helperText('Anyone more senior than everybody moving; the levels in between can be skipped.')
                ->required()
                ->native(false),

            DatePicker::make('effective_date')
                ->label('Effective date')
                ->helperText('Targets, achievement and team figures follow the move from this day.')
                ->maxDate(now())
                ->required()
                ->native(false)
                ->displayFormat('d F Y'),

            Textarea::make('remarks')
                ->label('Remarks (optional)')
                ->rows(2)
                ->maxLength(2000),
        ];
    }
}
