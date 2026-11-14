<?php

namespace App\Filament\Resources\EmployeeInactivityRequests\Tables;

use App\Enums\InactivityRequestStatus;
use App\Filament\Resources\EmployeeInactivityRequests\EmployeeInactivityRequestResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeInactivityRequest;
use App\Services\EmployeeDependencyService;
use App\Services\EmployeeInactivationService;
use App\Services\MonthlyTargetGate;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
     * flag, so the modal says so in as many words. It goes through
     * EmployeeInactivationService like "Mark Inactive" does: while anything
     * still depends on the employee the modal lists it and cannot be
     * submitted, and otherwise it asks for the effective (exit) date.
     */
    protected static function approveAction(): Action
    {
        $service = fn (): EmployeeDependencyService => app(EmployeeDependencyService::class);

        $stillOnRolls = fn (EmployeeInactivityRequest $record): bool => $record->employee !== null
            && ! EmployeeResource::hasLeft($record->employee);

        $dependencies = fn (EmployeeInactivityRequest $record): array => $stillOnRolls($record)
            ? $service()->dependencyCounts($record->employee)
            : [];

        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (EmployeeInactivityRequest $record): string => $dependencies($record) === []
                ? 'Approve this inactivity ticket'
                : "{$record->employee->emp_name} still has work attached")
            ->modalDescription(fn (EmployeeInactivityRequest $record): string => ($counts = $dependencies($record)) === []
                ? 'The employee is marked inactive across the whole LMS (exit status) from the effective date, and no commitment target is asked of them.'
                : 'Still attached: '.$service()->describe($counts).'. Move it to somebody else on their Employee page (“Mark Inactive” lists and clears it), then approve.')
            ->schema(fn (EmployeeInactivityRequest $record): array => ($counts = $dependencies($record)) !== []
                ? [
                    View::make('filament.components.delete-dependencies')
                        ->viewData([
                            'dependents' => collect($counts)
                                ->map(fn (int $count, string $kind): array => [
                                    'label' => $count.' '.Str::plural(EmployeeDependencyService::NOUNS[$kind], $count),
                                    'url' => EmployeeResource::getUrl('edit', ['record' => $record->employee]),
                                ])
                                ->values()
                                ->all(),
                            'total' => count($counts),
                            'noun' => 'dependency',
                            'heading' => 'Still attached',
                            'linkLabel' => 'Clear on employee page',
                        ]),
                ]
                : [
                    DatePicker::make('exit_date')
                        ->label('Effective date')
                        ->helperText('Their last day on the rolls. Targets, incentives and team counts stop counting them after it.')
                        ->default(today())
                        ->maxDate(now())
                        ->minDate(filled($record->employee?->doj) ? Carbon::parse($record->employee->doj) : null)
                        ->required()
                        ->native(false)
                        ->displayFormat('d F Y')
                        ->visible($stillOnRolls($record)),

                    Textarea::make('review_note')
                        ->label('Note (optional)')
                        ->rows(2)
                        ->maxLength(500),
                ])
            ->modalSubmitAction(fn (Action $action, EmployeeInactivityRequest $record): Action|false => $dependencies($record) === [] ? $action : false)
            ->visible(fn (EmployeeInactivityRequest $record): bool => EmployeeInactivityRequestResource::isReviewer()
                && $record->isPending())
            ->action(function (EmployeeInactivityRequest $record, array $data, Action $action) use ($stillOnRolls): void {
                if (! EmployeeInactivityRequestResource::isReviewer()) {
                    Notification::make()->title('Only an Admin can review a ticket.')->danger()->send();

                    return;
                }

                DB::transaction(function () use ($record, $data, $action, $stillOnRolls): void {
                    if ($stillOnRolls($record)) {
                        try {
                            app(EmployeeInactivationService::class)->inactivate(
                                $record->employee,
                                Carbon::parse($data['exit_date'] ?? today()),
                                $data['review_note'] ?? null,
                                Filament::auth()->user(),
                            );
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Not approved')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }

                    $record->update([
                        'status' => InactivityRequestStatus::Approved,
                        'reviewed_by' => Filament::auth()->id(),
                        'reviewed_at' => now(),
                        'review_note' => $data['review_note'] ?? null,
                    ]);
                });

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
