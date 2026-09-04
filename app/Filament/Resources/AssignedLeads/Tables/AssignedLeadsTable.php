<?php

namespace App\Filament\Resources\AssignedLeads\Tables;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Support\EmployeeOptions;
use App\Support\SelectedMonth;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AssignedLeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('display_name')
                    ->label('Prospect Name')
                    // Name lives on either the customer or the AI record, so
                    // sorting orders by whichever one this row points at.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        Customer::query()
                            ->select('customer_name')
                            ->whereColumn('customers.id', 'customer_assignments.customer_id')
                            ->limit(1),
                        $direction,
                    )),

                TextColumn::make('source_label')
                    ->label('Source')
                    ->badge()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        'customer_assignments.customer_id IS NULL '.($direction === 'asc' ? 'asc' : 'desc')
                    ))
                    ->color(fn (string $state): string => $state === 'Customer' ? 'success' : 'gray'),

                TextColumn::make('employee.emp_name')
                    ->label('Case Owner')
                    ->placeholder('Unassigned')
                    ->description(fn (CustomerAssignment $record): ?string => $record->employee?->emp_id)
                    ->searchable()
                    ->sortable()
                    ->visible(fn () => Filament::auth()->user()?->hasRole('Admin')
                        || Filament::auth()->user()?->employee?->designation !== Employee::DESIGNATION_CALLER),

                TextColumn::make('employee.emp_id')
                    ->label('Emp ID')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->visible(fn () => Filament::auth()->user()?->hasRole('Admin')
                        || Filament::auth()->user()?->employee?->designation !== Employee::DESIGNATION_CALLER),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    // "Opened"/"Pending" is derived from the open counter.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('opens_count', $direction))
                    ->color(fn (string $state): string => $state === 'Opened' ? 'success' : 'gray'),

                TextColumn::make('opens_count')
                    ->label('Opens')
                    ->sortable(),

                TextColumn::make('follow_up_status')
                    ->label('Follow-Up Status')
                    ->badge()
                    ->state(fn (CustomerAssignment $record) => $record->latestFollowUpStatus() ?? 'Pending')
                    ->color(fn (string $state): string => match ($state) {
                        'Interested' => 'success',
                        'Not Interested', 'Not Eligible' => 'danger',
                        'Busy', 'No Response' => 'warning',
                        'Eligible for Other Bank' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('next_follow_up')
                    ->label('Next Follow Up')
                    ->state(fn (CustomerAssignment $record) => $record->latestFollowUp()?->next_follow_up_date)
                    ->dateTime('d M Y h:i A')
                    ->placeholder('-'),

                TextColumn::make('customer.journey_status')
                    ->label('Journey')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'sanctioned' => 'Disbursed',
                        'sfl' => 'SFL',
                        'underwriting' => 'Underwriting',
                        'approved' => 'Approved',
                        default => $state ? ucfirst(str_replace('_', ' ', $state)) : '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'sfl' => 'gray',
                        'underwriting' => 'warning',
                        'approved' => 'info',
                        'sanctioned' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Assigned On')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                TextColumn::make('last_opened_at')
                    ->label('Last Opened')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Case Owner')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo(Filament::auth()->user()))
                    ->visible(fn () => Filament::auth()->user()?->hasRole('Admin')
                        || Filament::auth()->user()?->employee?->designation !== Employee::DESIGNATION_CALLER),

                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        'customer' => 'Customer',
                        'ai_record' => 'AI Record',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query) => $data['value'] === 'customer'
                            ? $query->whereNotNull('customer_id')
                            : $query->whereNull('customer_id')
                    )),

                SelectFilter::make('open_status')
                    ->label('Status')
                    ->options([
                        'opened' => 'Opened',
                        'pending' => 'Pending',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query) => $data['value'] === 'opened'
                            ? $query->where('opens_count', '>', 0)
                            : $query->where('opens_count', 0)
                    )),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('convertToCustomer')
                    ->label('Convert')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CustomerAssignment $record) => $record->isEligibleForConversion())
                    ->url(fn (CustomerAssignment $record) => CustomerResource::getUrl('create', [
                        'ai_customer_record' => $record->ai_customer_record_id,
                    ])),

                ViewAction::make(),
            ])
            ->modifyQueryUsing(
                fn (Builder $query) => $query->whereBetween('created_at', SelectedMonth::range())
            );
    }
}
