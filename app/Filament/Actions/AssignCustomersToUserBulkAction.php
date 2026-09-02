<?php

namespace App\Filament\Actions;

use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentBatch;
use App\Models\Employee;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

class AssignCustomersToUserBulkAction
{
    /**
     * @param  Closure|null  $idResolver  Given a selected record, returns the id to assign (a
     *                                    customer id, or an ai_customer_record id — see $targetType).
     *                                    Defaults to the record's own id.
     * @param  string  $targetType  Either 'customer' or 'ai_customer_record'.
     */
    public static function make(?Closure $idResolver = null, string $targetType = 'customer'): BulkAction
    {
        $idResolver ??= fn ($record) => $record->id;
        $column = $targetType === 'ai_customer_record' ? 'ai_customer_record_id' : 'customer_id';
        $noun = $targetType === 'ai_customer_record' ? 'record(s)' : 'customer(s)';

        return BulkAction::make('assignToUser')
            ->label('Assign to User')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn () => auth()->user()->hasRole('Admin'))
            ->form([
                Select::make('employee_id')
                    ->label('Assign To')
                    ->options(
                        Employee::whereIn('designation', [
                            Employee::DESIGNATION_CALLER,
                            Employee::DESIGNATION_TEAM_LEADER,
                        ])
                            ->orderBy('emp_name')
                            ->pluck('emp_name', 'id')
                    )
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data) use ($idResolver, $column, $noun): void {
                $assignedBy = auth()->user()->employee?->id;

                $targetIds = $records->map($idResolver)->filter()->unique()->values();

                if ($targetIds->isEmpty()) {
                    Notification::make()
                        ->title('Nothing assigned')
                        ->body('None of the selected rows could be assigned.')
                        ->warning()
                        ->send();

                    return;
                }

                $alreadyAssignedIds = CustomerAssignment::whereIn($column, $targetIds)->pluck($column);

                $toAssign = $targetIds->diff($alreadyAssignedIds);

                if ($toAssign->isEmpty()) {
                    Notification::make()
                        ->title('Nothing assigned')
                        ->body('All selected rows are already assigned to a user.')
                        ->warning()
                        ->send();

                    return;
                }

                $batch = CustomerAssignmentBatch::create([
                    'assigned_by' => $assignedBy,
                    'employee_id' => $data['employee_id'],
                    'customer_count' => $toAssign->count(),
                ]);

                foreach ($toAssign as $id) {
                    CustomerAssignment::create([
                        'batch_id' => $batch->id,
                        $column => $id,
                        'employee_id' => $data['employee_id'],
                        'assigned_by' => $assignedBy,
                    ]);
                }

                $skipped = $targetIds->count() - $toAssign->count();

                Notification::make()
                    ->title('Assigned')
                    ->body(
                        $toAssign->count() . " {$noun} assigned."
                        . ($skipped ? " {$skipped} were already assigned and skipped." : '')
                    )
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
