<?php

namespace App\Filament\Resources\Leads\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use App\Models\Lead;
use Filament\Tables\Columns\TextColumn;

use App\Models\Customer;
use Illuminate\Support\Str;
use Filament\Actions\Action;

use App\Filament\Imports\LeadImporter;
use Filament\Actions\ImportAction;
use Filament\Notifications\Notification;
use Filament\Actions\ViewAction;
use Filament\Tables\Filters\SelectFilter;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Filament\Facades\Filament;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                //
                TextColumn::make('customer_name')->label('Prospect Name')->searchable(),
                TextColumn::make('mobile_no')
                    ->label('Mobile No')
                    ->formatStateUsing(function (?string $state): string {
                        if (blank($state) || strlen($state) < 4) {
                            return $state ?? '-';
                        }

                        return 'XXXXXX' . substr($state, -4);
                    })
                    ->searchable(),
                TextColumn::make('current_location')->label('Location'),
                TextColumn::make('status')->badge(),

                TextColumn::make('bank.bank_name')
                    ->label('Bank')
                    ->searchable()
                    ->sortable(),


                TextColumn::make('follow_up_date')
                    ->label('Follow Up Created')
                    ->dateTime('d M Y h:i A')
                    ->badge()
                    ->sortable(),
                // TextColumn::make('next_follow_up_date')->date()->label('Next Follow Up'),
                TextColumn::make('next_follow_up_date')
                    ->label('Next Follow Up')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created On')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('convertToCustomer')
                    ->label('Convert')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    // Show button only when lead status is marked 'Interested'
                    ->visible(fn(Lead $record) => $record->status === 'Interested')
                    ->requiresConfirmation()
                    ->modalHeading('Convert Lead to Customer Profile?')
                    ->modalDescription('This will create a unique Customer profile record and initialize their active financial journey.')
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
                                ->body('Please fill the following field(s): ' . implode(', ', $missingFields))
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        // 1. Generate unique internal running application parameter
                        $generatedApplicationNo = 'APP-' . strtoupper(Str::random(8));

                        // 2. Create entry inside customers table matching your CustomerForm schema parameters
                        $customer = Customer::create([
                            'application_no' => $generatedApplicationNo,
                            'customer_name' => Str::title($record->customer_name),
                            'mobile_no' => $record->mobile_no,
                            'pan_number' => $record->pan_number,
                            'current_location' => $record->current_location,
                            'job_location' => $record->job_location,
                            'salary' => $record->salary,
                            'eligibility_status' => 'eligible', // Initial baseline default step
                            'journey_status' => 'sfl',          // Initial phase journey status step
                            'assign_to' => $record->employee_id,
                            'employee_id' => $record->employee_id,
                            'email' => $record->email,
                            'residence_location' => $record->residence_location,
                        ]);

                        // 3. Mark the lead as converted so it pulls out of this pending table layout automatically
                        $record->update([
                            'is_converted' => true,
                            'converted_customer_id' => $customer->id
                        ]);

                        Notification::make()
                            ->title('Lead converted successfully')
                            ->success()
                            ->send();
                    }),

                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // DeleteBulkAction::make(),
                ]),
            ])
            ->filters([

                SelectFilter::make('manager_id')
                    ->label('Manager')
                    ->multiple()
                    ->options(
                        Employee::where('designation', Employee::DESIGNATION_MANAGER)
                            ->orderBy('emp_name')
                            ->pluck('emp_name', 'id')
                    )
                    ->query(
                        fn(Builder $query, array $data) =>
                        $query->when(
                            filled($data['values'] ?? null),
                            fn(Builder $query) => $query->whereHas(
                                'employee',
                                fn(Builder $q) => $q->whereIn('manager_id', $data['values'])
                            )
                        )
                    ),

                SelectFilter::make('team_leader_id')
                    ->label('Team Leader')
                    ->multiple()
                    ->options(
                        Employee::where('designation', Employee::DESIGNATION_TEAM_LEADER)
                            ->orderBy('emp_name')
                            ->pluck('emp_name', 'id')
                    )
                    ->query(
                        fn(Builder $query, array $data) =>
                        $query->when(
                            filled($data['values'] ?? null),
                            fn(Builder $query) => $query->whereHas(
                                'employee',
                                fn(Builder $q) => $q->whereIn('superviser_id', $data['values'])
                            )
                        )
                    ),

                SelectFilter::make('caller_id')
                    ->label('Caller')
                    ->multiple()
                    ->options(
                        Employee::where('designation', Employee::DESIGNATION_CALLER)
                            ->orderBy('emp_name')
                            ->pluck('emp_name', 'id')
                    )
                    ->query(
                        fn(Builder $query, array $data) =>
                        $query->when(
                            filled($data['values'] ?? null),
                            fn(Builder $query) => $query->whereIn('employee_id', $data['values'])
                        )
                    ),

            ])
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
