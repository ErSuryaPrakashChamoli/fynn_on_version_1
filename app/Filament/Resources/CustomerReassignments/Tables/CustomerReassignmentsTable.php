<?php

namespace App\Filament\Resources\CustomerReassignments\Tables;

use App\Models\Customer;
use App\Models\CustomerReassignment;
use App\Models\Employee;
use App\Services\Journey\CustomerReassignmentService;
use App\Support\EmployeeOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerReassignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.application_no')
                    ->label('Application No')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('previousOwner.emp_name')
                    ->label('Previous Owner')
                    ->placeholder('—')
                    ->description(fn (CustomerReassignment $record): ?string => $record->previousOwner?->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('newOwner.emp_name')
                    ->label('New Owner')
                    ->description(fn (CustomerReassignment $record): ?string => $record->newOwner?->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reassignedBy.name')
                    ->label('Reassigned By')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reason')
                    ->limit(50)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reassigned_at')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),
            ])
            ->defaultSort('reassigned_at', 'desc')
            ->filters([
                SelectFilter::make('previous_owner_id')
                    ->label('Previous Owner')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo()),

                SelectFilter::make('new_owner_id')
                    ->label('New Owner')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo()),
            ])
            ->headerActions([
                Action::make('reassignCustomer')
                    ->label('Reassign Customer')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->modalHeading('Reassign a Customer')
                    ->modalDescription('Permanently moves this customer to a new owner. Historical ownership and every prior action remain unchanged in the audit trail.')
                    ->form([
                        Select::make('customer_id')
                            ->label('Customer')
                            // Typing searches every customer; with no search term
                            // the newest few are listed so the field is never an
                            // empty dropdown.
                            ->options(fn (): array => self::customerOptions())
                            ->getSearchResultsUsing(fn (string $search): array => self::customerOptions($search))
                            ->getOptionLabelUsing(function ($value): ?string {
                                $customer = Customer::find($value);

                                return $customer
                                    ? "{$customer->customer_name} ({$customer->application_no})"
                                    : null;
                            })
                            ->searchable()
                            ->required(),

                        Select::make('new_owner_id')
                            ->label('New Owner (Manager / Team Leader)')
                            ->options(fn (): array => self::ownerOptions())
                            ->searchable()
                            ->required(),

                        Textarea::make('reason')
                            ->label('Reason')
                            ->rows(3)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(CustomerReassignmentService::class)->reassign(
                                Customer::query()->findOrFail($data['customer_id']),
                                (int) $data['new_owner_id'],
                                (int) auth()->id(),
                                $data['reason'],
                            );

                            Notification::make()->success()->title('Customer reassigned')->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Could not reassign customer')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->persistent()
                                ->send();
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->danger()
                                ->title('Could not reassign customer')
                                ->body('An unexpected error occurred. No reassignment was made.')
                                ->send();
                        }
                    }),

                Action::make('managerExitBulkReassign')
                    ->label('Manager Exit — Bulk Reassign')
                    ->icon('heroicon-o-user-minus')
                    ->color('warning')
                    ->modalHeading('Bulk Reassign an Outgoing Manager\'s Customers')
                    ->modalDescription('Moves every customer currently under the outgoing Manager (including their Team Leaders/Callers) to a target Manager. Use this when a Manager exits or is permanently transferred.')
                    ->requiresConfirmation()
                    ->form([
                        Select::make('outgoing_manager_id')
                            ->label('Outgoing Manager')
                            ->options(fn (): array => self::managerOptions())
                            ->searchable()
                            ->required(),

                        Select::make('target_manager_id')
                            ->label('Target Manager')
                            ->options(fn (): array => self::managerOptions())
                            ->searchable()
                            ->required(),

                        Textarea::make('reason')
                            ->label('Reason')
                            ->rows(3)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $outgoing = Employee::query()->findOrFail($data['outgoing_manager_id']);
                            $target = Employee::query()->findOrFail($data['target_manager_id']);

                            $results = app(CustomerReassignmentService::class)->reassignAllForOutgoingManager(
                                $outgoing,
                                $target,
                                (int) auth()->id(),
                                $data['reason'],
                            );

                            Notification::make()
                                ->success()
                                ->title('Bulk reassignment complete')
                                ->body("{$results->count()} customer(s) moved from {$outgoing->emp_name} to {$target->emp_name}.")
                                ->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Could not complete bulk reassignment')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->persistent()
                                ->send();
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->danger()
                                ->title('Could not complete bulk reassignment')
                                ->body('An unexpected error occurred partway through. Check the reassignment history to see what completed.')
                                ->send();
                        }
                    }),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function customerOptions(?string $search = null): array
    {
        return Customer::query()
            ->when(
                filled($search),
                fn ($query) => $query->where(
                    fn ($q) => $q->where('customer_name', 'like', "%{$search}%")
                        ->orWhere('application_no', 'like', "%{$search}%")
                        ->orWhere('lan_no', 'like', "%{$search}%")
                ),
            )
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'customer_name', 'application_no'])
            ->mapWithKeys(fn (Customer $customer): array => [
                $customer->id => "{$customer->customer_name} ({$customer->application_no})",
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function ownerOptions(): array
    {
        return Employee::query()
            ->whereIn('designation', [Employee::DESIGNATION_MANAGER, Employee::DESIGNATION_TEAM_LEADER])
            ->where('exit_status', '!=', 'yes')
            ->orderBy('emp_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => "{$employee->emp_name} ({$employee->emp_id})",
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function managerOptions(): array
    {
        return Employee::query()
            ->where('designation', Employee::DESIGNATION_MANAGER)
            ->orderBy('emp_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => "{$employee->emp_name} ({$employee->emp_id})".($employee->exit_status === 'yes' ? ' [exited]' : ''),
            ])
            ->all();
    }
}
