<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Employee;
use App\Models\Lead;
use App\Support\EmployeeOptions;
use App\Support\SelectedMonth;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ImportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('customer_name')
                    ->label('Prospect Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.emp_name')
                    ->label('Case Owner')
                    ->placeholder('Unassigned')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.emp_id')
                    ->label('Emp ID')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('mobile_no')
                    ->label('Mobile No')
                    ->formatStateUsing(function (?string $state): string {
                        if (blank($state) || strlen($state) < 4) {
                            return $state ?? '-';
                        }

                        return 'XXXXXX'.substr($state, -4);
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('current_location')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('bank.bank_name')
                    ->label('Bank')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('next_follow_up_date')
                    ->label('Next Follow Up')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created On')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('convertToCustomer')
                    ->label('Convert')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    // Show button only when lead status is marked 'Interested'
                    ->visible(fn (Lead $record) => $record->status === 'Interested' && ! $record->is_converted)
                    ->requiresConfirmation()
                    ->modalHeading('Convert Lead to Customer Profile?')
                    ->modalDescription('This will check the PAN number for duplicates and take you to the Customer creation form. If the PAN already belongs to an existing customer, you will need to request admin approval before proceeding.')
                    ->action(function (Lead $record) {

                        $missingFields = [];

                        if (blank($record->pan_number)) {
                            $missingFields[] = 'PAN Number';
                        }

                        if (blank($record->job_location)) {
                            $missingFields[] = 'Job Location';
                        }

                        if (blank($record->salary)) {
                            $missingFields[] = 'Salary';
                        }

                        if (blank($record->email)) {
                            $missingFields[] = 'Email';
                        }

                        if (blank($record->residence_location)) {
                            $missingFields[] = 'Residence Location';
                        }

                        if (blank($record->current_location)) {
                            $missingFields[] = 'Current Location';
                        }

                        if (! empty($missingFields)) {
                            Notification::make()
                                ->title('Lead cannot be converted')
                                ->body('Please fill the following field(s): '.implode(', ', $missingFields))
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        // Hand off to the standard Customer creation form, which
                        // runs the same PAN duplicate check and admin approval
                        // flow used everywhere else customers are created.
                        return redirect(CustomerResource::getUrl('create', [
                            'lead' => $record->id,
                        ]));
                    }),

                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // DeleteBulkAction::make(),
                ]),
            ])
            ->filters([

                SelectFilter::make('employee_id')
                    ->label('Case Owner')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo())
                    ->query(
                        fn (Builder $query, array $data) => $query->when(
                            filled($data['values'] ?? null),
                            fn (Builder $query) => $query->whereIn('employee_id', $data['values'])
                        )
                    ),

                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options([
                        'Pending' => 'Pending',
                        'Interested' => 'Interested',
                        'Not Interested' => 'Not Interested',
                        'Busy' => 'Busy',
                        'No Response' => 'No Response',
                        'Not Eligible' => 'Not Eligible',
                        'Eligible for Other Bank' => 'Eligible for Other Bank',
                    ]),

                SelectFilter::make('bank_id')
                    ->label('Bank')
                    ->multiple()
                    ->relationship('bank', 'bank_name'),

                SelectFilter::make('manager_id')
                    ->label('Manager')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_MANAGER))
                    ->query(
                        fn (Builder $query, array $data) => $query->when(
                            filled($data['values'] ?? null),
                            fn (Builder $query) => $query->whereHas(
                                'employee',
                                fn (Builder $q) => $q->whereIn('manager_id', $data['values'])
                            )
                        )
                    ),

                SelectFilter::make('team_leader_id')
                    ->label('Team Leader')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_TEAM_LEADER))
                    ->query(
                        fn (Builder $query, array $data) => $query->when(
                            filled($data['values'] ?? null),
                            fn (Builder $query) => $query->whereHas(
                                'employee',
                                fn (Builder $q) => $q->whereIn('superviser_id', $data['values'])
                            )
                        )
                    ),

                SelectFilter::make('caller_id')
                    ->label('Caller')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_CALLER))
                    ->query(
                        fn (Builder $query, array $data) => $query->when(
                            filled($data['values'] ?? null),
                            fn (Builder $query) => $query->whereIn('employee_id', $data['values'])
                        )
                    ),

                Filter::make('next_follow_up_date')
                    ->label('Next Follow Up')
                    ->schema([
                        DatePicker::make('follow_up_from')->label('From'),
                        DatePicker::make('follow_up_until')->label('To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['follow_up_from'] ?? null,
                            fn (Builder $query, $date) => $query->whereDate('next_follow_up_date', '>=', $date),
                        )
                        ->when(
                            $data['follow_up_until'] ?? null,
                            fn (Builder $query, $date) => $query->whereDate('next_follow_up_date', '<=', $date),
                        ))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['follow_up_from'] ?? null) {
                            $indicators[] = 'Follow up from: '.Carbon::parse($data['follow_up_from'])->format('d M Y');
                        }

                        if ($data['follow_up_until'] ?? null) {
                            $indicators[] = 'Follow up to: '.Carbon::parse($data['follow_up_until'])->format('d M Y');
                        }

                        return $indicators;
                    }),

            ])
            ->modifyQueryUsing(
                fn (Builder $query) => $query->whereBetween('created_at', SelectedMonth::range())
            )
            ->headerActions([

                // ImportAction::make()
                // ->importer(LeadImporter::class),

                ImportAction::make()
                    ->label('Import Leads')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->importer(LeadImporter::class),

            ]);
    }
}
