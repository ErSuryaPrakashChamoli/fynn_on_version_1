<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Support\EmployeeOptions;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Inactivate" and "Activate" for an Employee Setup list whose inactivation
 * is dated (see HasEffectiveInactivation).
 *
 * Inactivate is two steps in one pop-up. While employees still hold the
 * option it lists them (each with a link to fix them one by one) and offers
 * to move them all to another option right there; once nobody holds it the
 * same pop-up asks for the date it stops being active.
 */
class EmployeeOptionActivationActions
{
    /**
     * @param  string  $optionLabel  What is being switched off, e.g. "unit".
     * @param  string  $fieldLabel  The Employee form field it fills, e.g. "Unit".
     * @param  Closure(Model): bool  $canManage
     */
    public static function inactivate(string $optionLabel, string $fieldLabel, Closure $canManage): Action
    {
        $employees = fn (int $count): string => $count.' '.Str::plural('employee', $count);

        return Action::make('inactivate')
            ->label('Inactivate')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->visible(fn (Model $record): bool => $record->is_active && $canManage($record))
            ->modalIcon(fn (Model $record): Heroicon => $record->employeesCount() > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedNoSymbol)
            ->modalIconColor('warning')
            ->modalHeading(fn (Model $record): string => $record->employeesCount() > 0
                ? "“{$record->name}” is still in use"
                : "Inactivate “{$record->name}”")
            ->modalDescription(function (Model $record) use ($employees, $optionLabel): string {
                $count = $record->employeesCount();

                return $count > 0
                    ? "{$employees($count)} still ".($count === 1 ? 'has' : 'have')." this {$optionLabel}. Move them to another {$optionLabel} first — then you choose the date it stops being active."
                    : 'It is no longer offered on the Employee form. Anything worked out for a date before the effective date still treats it as active.';
            })
            ->schema(function (Model $record) use ($optionLabel, $fieldLabel): array {
                $count = $record->employeesCount();

                if ($count === 0) {
                    return [
                        DatePicker::make('inactive_from')
                            ->label('Effective date')
                            ->helperText('The first day it no longer counts as active.')
                            ->default(today())
                            ->maxDate(now())
                            ->required()
                            ->native(false)
                            ->displayFormat('d F Y'),
                    ];
                }

                $options = $record->replacementOptions();

                return [
                    View::make('filament.components.delete-dependencies')
                        ->viewData([
                            'dependents' => $record->dependentEmployees()
                                ->orderBy('emp_name')
                                ->limit(DeleteWithDependenciesAction::LISTED_DEPENDENTS)
                                ->get()
                                ->map(fn (Employee $employee): array => [
                                    'label' => EmployeeOptions::label($employee),
                                    'url' => EmployeeResource::getUrl('edit', ['record' => $employee]),
                                ])
                                ->all(),
                            'total' => $count,
                            'noun' => 'employee',
                        ]),

                    $options === []
                        ? Text::make("There is no other active {$optionLabel} to move them to. Add or activate one on this screen, or change each employee using the links above, then inactivate again.")
                        : Select::make('replacement')
                            ->label('Move these employees to')
                            ->options($options)
                            ->required()
                            ->native(false)
                            ->helperText("Each one's {$fieldLabel} changes from “{$record->name}” to your choice. To give them different {$optionLabel}s, use the Edit links above instead."),
                ];
            })
            ->modalSubmitAction(fn (Action $action, Model $record): Action|false => $record->employeesCount() > 0 && $record->replacementOptions() === []
                ? false
                : $action)
            ->modalSubmitActionLabel(fn (Model $record): string => ($count = $record->employeesCount()) > 0
                ? "Move {$employees($count)}"
                : 'Inactivate')
            ->action(function (Model $record, array $data, Action $action) use ($employees, $optionLabel): void {
                $count = $record->employeesCount();

                if ($count > 0) {
                    $replacement = $data['replacement'] ?? null;

                    if (blank($replacement) || ! array_key_exists($replacement, $record->replacementOptions())) {
                        Notification::make()
                            ->title("Choose the {$optionLabel} to move them to")
                            ->danger()
                            ->send();

                        $action->halt();
                    }

                    $moved = $record->moveEmployeesTo($replacement);

                    Notification::make()
                        ->title("{$employees($moved)} moved")
                        ->body("Nobody holds “{$record->name}” now. Choose the date it stops being active.")
                        ->success()
                        ->send();

                    ReopenAction::for($action);

                    return;
                }

                DB::transaction(fn () => $record->inactivate(Carbon::parse($data['inactive_from'])));

                Notification::make()
                    ->title("“{$record->name}” inactivated")
                    ->body('Effective from '.$record->inactive_from->format('d M Y').'.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  Closure(Model): bool  $canManage
     */
    public static function activate(string $optionLabel, Closure $canManage): Action
    {
        return Action::make('activate')
            ->label('Activate')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Model $record): bool => ! $record->is_active && $canManage($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Model $record): string => "Activate “{$record->name}”?")
            ->modalDescription("It is offered on the Employee form again and counts as an active {$optionLabel} from now on.")
            ->action(function (Model $record): void {
                $record->activate();

                Notification::make()
                    ->title("“{$record->name}” activated")
                    ->success()
                    ->send();
            });
    }
}
