<?php

namespace App\Filament\Imports;

use Filament\Forms\Components\Radio;

/**
 * "Update Existing Employees": EmployeeImporter in update mode, with the
 * choice of how somebody given a new boss in the file moves. Its own class
 * because the choice belongs only in the update pop-up — an importer's
 * options form is shown by every action that uses it.
 */
class EmployeeUpdateImporter extends EmployeeImporter
{
    public static function getOptionsFormComponents(): array
    {
        return [
            Radio::make('move')
                ->label('When the file gives someone a new boss (same designation)')
                ->options([
                    self::MOVE_ALONE => 'Move only the employee',
                    self::MOVE_WITH_TEAM => 'Move the employee with their team',
                ])
                ->descriptions([
                    self::MOVE_ALONE => 'Their row is refused while a team, open customers or leads are still assigned to them. Hand those over first.',
                    self::MOVE_WITH_TEAM => 'Everyone under them moves too, and they keep their customers and leads.',
                ])
                ->default(self::MOVE_ALONE)
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected static function updatesExisting(array $options): bool
    {
        return true;
    }
}
