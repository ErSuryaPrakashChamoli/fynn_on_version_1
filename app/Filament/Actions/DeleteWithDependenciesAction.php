<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Support\EmployeeOptions;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Delete" for the Employee Setup lists. When nobody holds the option it is
 * a plain confirmation. When somebody still does, the pop-up lists who (each
 * with a link to fix them one by one) and offers to move them all to another
 * option right there; the option is deleted once they have been moved.
 */
class DeleteWithDependenciesAction
{
    /**
     * How many dependents are listed by name in the pop-up.
     */
    public const LISTED_DEPENDENTS = 25;

    /**
     * For a list stored on employees (see IsEmployeeOption): the dependents
     * are the employees holding it, fixed on their Employee form.
     *
     * @param  Closure(Model): bool  $canDelete
     */
    public static function forEmployeeOption(string $optionLabel, string $fieldLabel, Closure $canDelete): Action
    {
        return self::make(
            optionLabel: $optionLabel,
            dependentNoun: 'employee',
            fieldLabel: $fieldLabel,
            countDependents: fn (Model $record): int => $record->employeesCount(),
            listDependents: fn (Model $record, int $limit): array => $record->dependentEmployees()
                ->orderBy('emp_name')
                ->limit($limit)
                ->get()
                ->map(fn (Employee $employee): array => [
                    'label' => EmployeeOptions::label($employee),
                    'url' => EmployeeResource::getUrl('edit', ['record' => $employee]),
                ])
                ->all(),
            replacementOptions: fn (Model $record): array => $record->replacementOptions(),
            moveDependents: fn (Model $record, int|string $replacement) => $record->moveEmployeesTo($replacement),
            canDelete: $canDelete,
        );
    }

    /**
     * @param  string  $optionLabel  What is being deleted, e.g. "designation".
     * @param  string  $dependentNoun  Who holds it, e.g. "employee".
     * @param  string  $fieldLabel  The field that changes on each dependent, e.g. "Designation".
     * @param  Closure(Model): int  $countDependents
     * @param  Closure(Model, int): list<array{label: string, url: string|null}>  $listDependents
     * @param  Closure(Model): array<int|string, string>  $replacementOptions
     * @param  Closure(Model, int|string): void  $moveDependents
     * @param  Closure(Model): bool  $canDelete  Authorisation / built-in check; dependents are handled here.
     */
    public static function make(
        string $optionLabel,
        string $dependentNoun,
        string $fieldLabel,
        Closure $countDependents,
        Closure $listDependents,
        Closure $replacementOptions,
        Closure $moveDependents,
        Closure $canDelete,
    ): Action {
        $nouns = fn (int $count): string => $count.' '.Str::plural($dependentNoun, $count);

        return Action::make('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (Model $record): bool => $canDelete($record))
            ->modalIcon(fn (Model $record): Heroicon => $countDependents($record) > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedTrash)
            ->modalIconColor(fn (Model $record): string => $countDependents($record) > 0 ? 'warning' : 'danger')
            ->modalHeading(fn (Model $record): string => $countDependents($record) > 0
                ? "“{$record->name}” is still in use"
                : "Delete “{$record->name}”?")
            ->modalDescription(function (Model $record) use ($countDependents, $nouns, $optionLabel): string {
                $count = $countDependents($record);

                return $count > 0
                    ? "{$nouns($count)} still ".($count === 1 ? 'has' : 'have')." this {$optionLabel}. Move them to another {$optionLabel} first — it is deleted as soon as nobody holds it."
                    : "Nobody holds this {$optionLabel}. Deleting it cannot be undone.";
            })
            ->schema(function (Model $record) use ($countDependents, $listDependents, $replacementOptions, $optionLabel, $dependentNoun, $fieldLabel): array {
                $count = $countDependents($record);

                if ($count === 0) {
                    return [];
                }

                $options = $replacementOptions($record);
                $plural = Str::plural($dependentNoun);

                return [
                    View::make('filament.components.delete-dependencies')
                        ->viewData([
                            'dependents' => $listDependents($record, self::LISTED_DEPENDENTS),
                            'total' => $count,
                            'noun' => $dependentNoun,
                        ]),

                    $options === []
                        ? Text::make("There is no other active {$optionLabel} to move them to. Add or activate one on this screen, or change each {$dependentNoun} using the links above, then delete again.")
                        : Select::make('replacement')
                            ->label("Move these {$plural} to")
                            ->options($options)
                            ->required()
                            ->native(false)
                            ->helperText("Suggestion: pick the {$optionLabel} these {$plural} should have instead. Each one's {$fieldLabel} changes from “{$record->name}” to your choice, and then “{$record->name}” is deleted. To give them different {$optionLabel}s, use the Edit links above instead."),
                ];
            })
            ->modalSubmitAction(fn (Action $action, Model $record): Action|false => $countDependents($record) > 0 && $replacementOptions($record) === []
                ? false
                : $action)
            ->modalSubmitActionLabel(fn (Model $record): string => ($count = $countDependents($record)) > 0
                ? "Move {$nouns($count)} & delete"
                : 'Delete')
            ->action(function (Model $record, array $data, Action $action) use ($countDependents, $replacementOptions, $moveDependents, $optionLabel): void {
                $replacement = $data['replacement'] ?? null;

                if ($countDependents($record) > 0 && (blank($replacement) || ! array_key_exists($replacement, $replacementOptions($record)))) {
                    Notification::make()
                        ->title("“{$record->name}” is in use")
                        ->body("Choose the {$optionLabel} to move them to, then delete again.")
                        ->danger()
                        ->send();

                    $action->halt();
                }

                DB::transaction(function () use ($record, $replacement, $countDependents, $moveDependents): void {
                    if ($countDependents($record) > 0) {
                        $moveDependents($record, $replacement);
                    }

                    $record->delete();
                });

                Notification::make()
                    ->title("“{$record->name}” deleted")
                    ->success()
                    ->send();
            });
    }
}
