<?php

namespace App\Filament\Resources\FollowUps\Schemas;

use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\AiCustomerRecord;
use App\Models\Bank;
use App\Models\FollowUp;
use Coolsam\Flatpickr\Forms\Components\Flatpickr;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FollowUpForm
{
    public static function configure(Schema $schema): Schema
    {
        // Only prospects the user may work on can be prefilled — the ids
        // arrive in the query string and must not reveal anyone else's file.
        $customer = filled(request('customer'))
            ? CustomerResource::getEloquentQuery()->find(request('customer'))
            : null;
        $aiRecord = ! $customer && filled(request('ai_customer_record'))
            && (auth()->user()?->hasRole('Admin') || AssignedLeadResource::getEloquentQuery()->where('customer_assignments.ai_customer_record_id', request('ai_customer_record'))->exists())
            ? AiCustomerRecord::find(request('ai_customer_record'))
            : null;

        return $schema
            ->schema([

                Section::make('Customer Details')
                    ->description(
                        $aiRecord
                            ? 'This lead has not been converted into a customer profile yet — details below come from the AI-extracted document.'
                            : null
                    )
                    ->schema([

                        TextInput::make('customer_name')
                            ->label('Customer Name')
                            ->default($customer?->customer_name ?? $aiRecord?->value('customer_name'))
                            ->afterStateHydrated(fn (TextInput $component, ?FollowUp $record) => $record
                                ? $component->state(self::subjectDetails($record)['customer_name'])
                                : null)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('mobile_no')
                            ->label('Phone')
                            ->default($customer?->mobile_no ?? $aiRecord?->value('mobile_number'))
                            ->afterStateHydrated(fn (TextInput $component, ?FollowUp $record) => $record
                                ? $component->state(self::subjectDetails($record)['mobile_no'])
                                : null)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('email')
                            ->label('Email Address')
                            ->default($customer?->email)
                            ->afterStateHydrated(fn (TextInput $component, ?FollowUp $record) => $record
                                ? $component->state(self::subjectDetails($record)['email'])
                                : null)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('pan_number')
                            ->label('PAN Number')
                            ->default($customer?->pan_number)
                            ->afterStateHydrated(fn (TextInput $component, ?FollowUp $record) => $record
                                ? $component->state(self::subjectDetails($record)['pan_number'])
                                : null)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('current_location')
                            ->label('Current Location')
                            ->default($customer?->current_location)
                            ->afterStateHydrated(fn (TextInput $component, ?FollowUp $record) => $record
                                ? $component->state(self::subjectDetails($record)['current_location'])
                                : null)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('job_location')
                            ->label('Job Location')
                            ->default($customer?->job_location)
                            ->afterStateHydrated(fn (TextInput $component, ?FollowUp $record) => $record
                                ? $component->state(self::subjectDetails($record)['job_location'])
                                : null)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('salary')
                            ->label('Salary')
                            ->default(
                                $customer?->salary
                                    ? '₹'.number_format($customer->salary)
                                    : ''
                            )
                            ->afterStateHydrated(fn (TextInput $component, ?FollowUp $record) => $record
                                ? $component->state(self::subjectDetails($record)['salary'])
                                : null)
                            ->disabled()
                            ->dehydrated(false),

                    ])
                    ->columns(2),

                Section::make('Follow Up')
                    ->description('This follow-up is recorded against today. Set the next follow-up date to say when the customer is due again — it replaces any date set previously, and every change stays visible in the follow-up log.')
                    ->schema([

                        Select::make('follow_up_type')
                            ->options([
                                'Call' => 'Call',
                                'WhatsApp' => 'WhatsApp',
                                'Email' => 'Email',
                                'Visit' => 'Visit',
                            ])
                            ->required(),

                        // Select::make('status')
                        //     ->label('Status')
                        //     ->options([
                        //         'Pending' => 'Pending',
                        //         'Interested' => 'Interested',
                        //         'Not Interested' => 'Not Interested',
                        //         'Busy' => 'Busy',
                        //         'No Response' => 'No Response',
                        //         'Not Eligible' => 'Not Eligible',
                        //         'Eligible for Other Bank' => 'Eligible for Other Bank',
                        //     ])
                        //     ->default('Pending')
                        //     ->live()
                        //     ->required(),

                        // Select::make('status')
                        //     ->label('Status')
                        //     ->options([
                        //         'Pending' => 'Pending',
                        //         'Interested' => 'Interested',
                        //         'Not Interested' => 'Not Interested',
                        //         'Busy' => 'Busy',
                        //         'No Response' => 'No Response',
                        //         'Not Eligible' => 'Not Eligible',
                        //         'Eligible for Other Bank' => 'Eligible for Other Bank',
                        //     ])
                        //     ->default('Pending')
                        //     ->live()
                        //     ->required()
                        //     ->afterStateUpdated(function ($state, $set) {
                        //         if (in_array($state, ['Not Interested', 'Not Eligible'])) {
                        //             $set('next_follow_up_date', null);
                        //         }

                        //         if ($state !== 'Eligible for Other Bank') {
                        //             $set('bank_id', null);
                        //         }
                        //     }),

                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'Awaiting Low ROI' => 'Awaiting Low ROI',
                                'Awaiting PF Waiver' => 'Awaiting PF Waiver',
                                'Converted' => 'Converted',
                                'Delay Multifunding' => 'Delay Multifunding',
                                'Dropped' => 'Dropped',
                                'Journey Started' => 'Journey Started',
                                'Lost' => 'Lost',
                                'On Hold' => 'On Hold',
                                'Out of Station' => 'Out of Station',
                            ])
                            ->default('Awaiting Low ROI')
                            ->live()
                            ->required()
                            ->afterStateUpdated(function ($state, $set) {
                                // Clear follow-up date for statuses where follow-up
                                // is not applicable.
                                if (in_array($state, ['Converted', 'Lost', 'Dropped'])) {
                                    $set('next_follow_up_date', null);
                                }
                            }),

                        Select::make('bank_id')
                            ->label('Bank Name')
                            ->options(
                                fn () => Bank::query()
                                    ->where('is_active', 1)
                                    ->orderBy('bank_name')
                                    ->pluck('bank_name', 'id')
                                    ->toArray()
                            )
                            ->searchable()
                            ->preload()
                            ->required(
                                fn ($get) => $get('status') === 'Eligible for Other Bank'

                            )
                            ->visible(
                                fn ($get) => $get('status') === 'Eligible for Other Bank'
                            )
                            ->live()
                            ->afterStateUpdated(function ($state, $set, $get) {
                                if ($get('status') !== 'Eligible for Other Bank') {
                                    $set('bank_id', null);
                                }
                            }),

                        // DateTimePicker::make('next_follow_up_date')
                        //     ->label('Next Follow Up Date & Time')
                        //     ->displayFormat('d F Y h:i A')
                        //     ->native(false)
                        //     ->seconds(false)
                        //     ->minDate(today())
                        //     ->required(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     )
                        //     ->visible(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     )
                        //     ->placeholder('Select date & time')
                        //     ->helperText('Select both date and time for the next follow-up.'),

                        Flatpickr::make('next_follow_up_date')
                            ->label('Next Follow Up Date & Time')
                            ->time(true)
                            ->time24hr(false)
                            ->seconds(false)
                            ->minuteIncrement(15)
                            ->format('Y-m-d H:i')
                            ->displayFormat('d M Y h:i K')
                            ->minDate(today())
                            // ->required(
                            //     fn($get) => ! in_array($get('status'), [
                            //         'Not Interested',
                            //         'Not Eligible',
                            //     ])
                            // )
                            // ->visible(
                            //     fn($get) => ! in_array($get('status'), [
                            //         'Not Interested',
                            //         'Not Eligible',
                            //     ])
                            // )
                            ->required(
                                fn ($get) => ! in_array($get('status'), [
                                    'Converted',
                                    'Lost',
                                ])
                            )
                            ->visible(
                                fn ($get) => ! in_array($get('status'), [
                                    'Converted',
                                    'Lost',
                                ])
                            )
                            ->placeholder('Select date & time')
                            ->suffixIcon('heroicon-m-calendar')
                            ->helperText('Select date and time for the next follow-up.'),

                        Textarea::make('remarks')
                            ->rows(5)
                            ->required()
                            ->columnSpanFull(),

                        Hidden::make('customer_id')
                            ->default($customer?->id)
                            ->dehydrated(true)
                            ->required(fn (Get $get) => blank($get('ai_customer_record_id'))),

                        Hidden::make('ai_customer_record_id')
                            ->default($aiRecord?->id)
                            ->dehydrated(true)
                            ->required(fn (Get $get) => blank($get('customer_id'))),

                        Hidden::make('employee_id')
                            ->default(fn () => auth()->user()?->employee?->id)
                            ->dehydrated(true),

                    ])
                    ->columns(2),

            ]);
    }

    /**
     * The read-only details shown for the prospect an existing follow-up was
     * logged against — its customer, AI-extracted record or lead.
     *
     * @return array{customer_name: ?string, mobile_no: ?string, email: ?string, pan_number: ?string, current_location: ?string, job_location: ?string, salary: ?string}
     */
    public static function subjectDetails(FollowUp $followUp): array
    {
        $subject = $followUp->customer ?? $followUp->lead;
        $aiRecord = $subject ? null : $followUp->aiCustomerRecord;

        return [
            'customer_name' => $subject?->customer_name ?? $aiRecord?->value('customer_name'),
            'mobile_no' => $subject?->mobile_no ?? $aiRecord?->value('mobile_number'),
            'email' => $subject?->email,
            'pan_number' => $subject?->pan_number,
            'current_location' => $subject?->current_location,
            'job_location' => $subject?->job_location,
            'salary' => $subject?->salary ? '₹'.number_format((float) $subject->salary) : null,
        ];
    }
}
