<?php

namespace App\Filament\Actions;

use App\Models\Employee;
use App\Services\CustomerAssignmentService;
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
        $noun = $targetType === CustomerAssignmentService::TARGET_AI_RECORD ? 'record(s)' : 'customer(s)';

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
            ->action(function (Collection $records, array $data) use ($idResolver, $targetType, $noun): void {
                $targetIds = $records->map($idResolver)->filter()->unique()->values();

                if ($targetIds->isEmpty()) {
                    Notification::make()
                        ->title('Nothing assigned')
                        ->body('None of the selected rows could be assigned.')
                        ->warning()
                        ->send();

                    return;
                }

                $result = app(CustomerAssignmentService::class)->assign(
                    $targetIds,
                    (int) $data['employee_id'],
                    $targetType,
                    auth()->user()->employee?->id,
                );

                if ($result['assigned'] === 0) {
                    Notification::make()
                        ->title('Nothing assigned')
                        ->body('All selected rows are already assigned to a user.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Assigned')
                    ->body(
                        $result['assigned']." {$noun} assigned."
                        .($result['skipped'] ? " {$result['skipped']} were already assigned and skipped." : '')
                    )
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
