<?php

namespace App\Filament\Resources\EmployeeInactivityRequests\Tables;

use App\Enums\InactivityRequestStatus;
use App\Filament\Resources\EmployeeInactivityRequests\EmployeeInactivityRequestResource;
use App\Models\Employee;
use App\Models\EmployeeInactivityRequest;
use App\Services\MonthlyTargetGate;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class EmployeeInactivityRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('employee.emp_name')
                    ->label('Employee')
                    ->description(fn (EmployeeInactivityRequest $record): ?string => $record->employee?->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.emp_id')
                    ->label('Employee ID')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.designation')
                    ->label('Role')
                    ->formatStateUsing(fn ($state): string => Employee::designationOptions()[$state] ?? '—')
                    ->sortable(),

                TextColumn::make('month')
                    ->label('Target month skipped')
                    ->date('M Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (InactivityRequestStatus $state): string => $state->label())
                    ->color(fn (InactivityRequestStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->wrap()
                    ->limit(80)
                    ->searchable(),

                TextColumn::make('requester.name')
                    ->label('Raised by')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Raised')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                TextColumn::make('reviewer.name')
                    ->label('Reviewed by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->dateTime('d M Y, H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InactivityRequestStatus::options()),

                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'emp_name'),

                SelectFilter::make('designation')
                    ->label('Role')
                    ->options(Employee::designationOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('employee', fn (Builder $employee): Builder => $employee->where('designation', $data['value']))
                        : $query),

                Filter::make('current_month')
                    ->label('This month only')
                    ->query(fn (Builder $query): Builder => $query->whereDate('month', Carbon::today()->startOfMonth()->toDateString())),
            ])
            ->recordActions([
                self::approveAction(),
                self::rejectAction(),
                DeleteAction::make()
                    ->visible(fn (EmployeeInactivityRequest $record): bool => EmployeeInactivityRequestResource::canDelete($record)),
            ]);
    }

    /**
     * Approving is the point the employee actually comes off the rolls —
     * exit_status is the app's single "is this person still with us?"
     * flag, so the modal says so in as many words.
     */
    protected static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve this inactivity ticket')
            ->modalDescription('The employee is marked inactive across the whole LMS (exit status), and no commitment target is asked of them.')
            ->schema([
                Textarea::make('review_note')
                    ->label('Note (optional)')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->visible(fn (EmployeeInactivityRequest $record): bool => EmployeeInactivityRequestResource::isReviewer()
                && $record->isPending())
            ->action(function (EmployeeInactivityRequest $record, array $data): void {
                if (! EmployeeInactivityRequestResource::isReviewer()) {
                    Notification::make()->title('Only an Admin can review a ticket.')->danger()->send();

                    return;
                }

                $record->update([
                    'status' => InactivityRequestStatus::Approved,
                    'reviewed_by' => Filament::auth()->id(),
                    'reviewed_at' => now(),
                    'review_note' => $data['review_note'] ?? null,
                ]);

                $record->employee?->forceFill([
                    'exit_status' => 'yes',
                    'exit_date' => $record->employee->exit_date ?? today()->toDateString(),
                ])->save();

                app(MonthlyTargetGate::class)->forget();

                Notification::make()->title('Ticket approved')->success()->send();
            });
    }

    /**
     * Rejecting puts the target back: the month blocks again until
     * somebody fixes a real number for that employee.
     */
    protected static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Reject this inactivity ticket')
            ->modalDescription('Their monthly commitment target is required again, and the month blocks until it is set.')
            ->schema([
                Textarea::make('review_note')
                    ->label('Why is it rejected?')
                    ->rows(2)
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (EmployeeInactivityRequest $record): bool => EmployeeInactivityRequestResource::isReviewer()
                && $record->isPending())
            ->action(function (EmployeeInactivityRequest $record, array $data): void {
                if (! EmployeeInactivityRequestResource::isReviewer()) {
                    Notification::make()->title('Only an Admin can review a ticket.')->danger()->send();

                    return;
                }

                $record->update([
                    'status' => InactivityRequestStatus::Rejected,
                    'reviewed_by' => Filament::auth()->id(),
                    'reviewed_at' => now(),
                    'review_note' => $data['review_note'],
                ]);

                app(MonthlyTargetGate::class)->forget();

                Notification::make()->title('Ticket rejected')->success()->send();
            });
    }
}
