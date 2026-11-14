<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeInactivationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Mark Inactive" on the Employee page — the only way an employee on the
 * rolls is taken off them from the admin screens.
 *
 * Two steps in one pop-up. While anything is still attached to the
 * employee it is the EmployeeDependencyStep: listed, and handed over or
 * cancelled right there. Once nothing is left the same pop-up asks for the
 * effective (exit) date, which every target and incentive calculation reads.
 */
class InactivateEmployeeAction
{
    public static function make(): Action
    {
        $attached = fn (Employee $record): bool => EmployeeDependencyStep::dependencies($record) !== [];

        return Action::make('inactivate')
            ->label('Mark Inactive')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->visible(fn (Employee $record): bool => ! EmployeeResource::hasLeft($record))
            ->modalWidth(fn (Employee $record): Width => $attached($record) ? Width::ThreeExtraLarge : Width::Large)
            ->modalIcon(fn (Employee $record): Heroicon => $attached($record) ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedUserMinus)
            ->modalIconColor(fn (Employee $record): string => $attached($record) ? 'warning' : 'danger')
            ->modalHeading(fn (Employee $record): string => $attached($record)
                ? EmployeeDependencyStep::heading($record)
                : "Mark {$record->emp_name} inactive")
            ->modalDescription(fn (Employee $record): string => $attached($record)
                ? EmployeeDependencyStep::description($record, 'The effective date is asked for once nothing is left.')
                : 'Nothing is attached to them any more. They can no longer sign in, and calculations stop counting them after the effective date.')
            ->fillForm(fn (array $arguments): array => [
                'handover_date' => today()->toDateString(),
                'exit_date' => $arguments['exit_date'] ?? today()->toDateString(),
            ])
            ->schema(fn (Employee $record): array => $attached($record)
                ? EmployeeDependencyStep::schema($record)
                : self::exitSchema($record))
            ->modalSubmitActionLabel(fn (Employee $record): string => $attached($record) ? 'Hand over & continue' : 'Mark inactive')
            ->action(function (Employee $record, array $data, Action $action, $livewire) use ($attached): void {
                if ($attached($record)) {
                    EmployeeDependencyStep::clear($record, $data, $action);

                    ReopenAction::for($action, ['exit_date' => Carbon::parse($data['handover_date'] ?? today())->toDateString()]);

                    return;
                }

                try {
                    app(EmployeeInactivationService::class)->inactivate($record, Carbon::parse($data['exit_date']), $data['remarks'] ?? null, Auth::user());
                } catch (ValidationException $exception) {
                    EmployeeDependencyStep::refuse($exception, $action);
                }

                $record->refresh();

                if ($livewire instanceof EditRecord) {
                    $livewire->refreshFormData(['exit_status', 'exit_date']);
                }

                Notification::make()
                    ->title("{$record->emp_name} marked inactive")
                    ->body('Effective from '.Carbon::parse($record->exit_date)->format('d M Y').'.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return list<Component>
     */
    private static function exitSchema(Employee $employee): array
    {
        return [
            DatePicker::make('exit_date')
                ->label('Effective date')
                ->helperText('Their last day on the rolls. Targets, incentives and team counts stop counting them after it.')
                ->maxDate(now())
                ->minDate(filled($employee->doj) ? Carbon::parse($employee->doj) : null)
                ->required()
                ->native(false)
                ->displayFormat('d F Y'),

            Textarea::make('remarks')
                ->label('Remarks (optional)')
                ->rows(2)
                ->maxLength(500),
        ];
    }
}
