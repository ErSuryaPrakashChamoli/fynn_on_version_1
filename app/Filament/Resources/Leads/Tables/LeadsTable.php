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

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->query(Lead::query()->where('is_converted', false))
            ->columns([
                //
                TextColumn::make('customer_name')->label('Prospect Name')->searchable(),
                TextColumn::make('mobile_no')->label('Phone'),
                TextColumn::make('current_location')->label('Location'),
                TextColumn::make('status')->badge(),
                TextColumn::make('follow_up_date')->label('Follow up created')->badge(),
                TextColumn::make('next_follow_up_date')->date()->label('Next Follow Up'),
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
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
