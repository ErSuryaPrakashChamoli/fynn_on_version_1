<?php

namespace App\Filament\Resources\AssignedLeads\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssignedLeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('AI Extracted Data')
                    ->description('This lead has not been approved into a customer profile yet. Contact details below come directly from the AI-extracted document.')
                    ->columnSpanFull()
                    ->visible(fn ($record) => filled($record->ai_customer_record_id))
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('aiCustomerRecord.schema.name')
                                    ->label('Configuration'),

                                TextEntry::make('aiCustomerRecord.status')
                                    ->label('Review Status')
                                    ->badge(),

                                TextEntry::make('aiCustomerRecord.confidence_score')
                                    ->label('Confidence')
                                    ->formatStateUsing(fn ($state) => $state === null ? '-' : number_format((float) $state * 100, 1).'%'),
                            ]),

                        TextEntry::make('aiCustomerRecord.data')
                            ->label('Extracted Fields')
                            ->html()
                            ->columnSpanFull()
                            ->formatStateUsing(function ($state, $record) {
                                $labels = collect($record->aiCustomerRecord?->schema?->getFieldDefinitions() ?? [])
                                    ->pluck('label', 'key');

                                $rows = collect($state ?? [])->map(function ($value, $key) use ($labels) {
                                    $label = e($labels->get($key, ucfirst(str_replace('_', ' ', $key))));
                                    $value = ($value === null || $value === '') ? '-' : e($value);

                                    return "<div class='mb-1'><strong>{$label}:</strong> {$value}</div>";
                                })->implode('');

                                return $rows !== '' ? $rows : '<span class="text-gray-500">No data extracted.</span>';
                            }),
                    ]),

                Section::make('Customer Details')
                    ->columnSpanFull()
                    ->visible(fn ($record) => filled($record->customer_id))
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('customer.customer_name')
                                    ->label('Customer Name'),

                                TextEntry::make('customer.mobile_no')
                                    ->label('Mobile'),

                                TextEntry::make('customer.email')
                                    ->label('Email')
                                    ->placeholder('-'),

                                TextEntry::make('customer.pan_number')
                                    ->label('PAN')
                                    ->badge(),

                                TextEntry::make('customer.current_location')
                                    ->label('Current Location'),

                                TextEntry::make('customer.residence_location')
                                    ->label('Residence Location'),

                                TextEntry::make('customer.job_location')
                                    ->label('Job Location'),

                                TextEntry::make('customer.salary')
                                    ->label('Salary')
                                    ->money('INR'),

                                TextEntry::make('customer.eligibility_status')
                                    ->label('Eligibility')
                                    ->badge(),
                            ]),
                    ]),

                Section::make('Loan Details')
                    ->columnSpanFull()
                    ->visible(fn ($record) => filled($record->customer_id))
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('customer.loan_applied')
                                    ->label('Loan Applied'),

                                TextEntry::make('customer.bank_eligible_for')
                                    ->label('Bank Eligible For'),

                                TextEntry::make('customer.journey_status')
                                    ->label('Journey Status')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                                        'sanctioned' => 'Disbursed',
                                        'sfl' => 'SFL',
                                        'underwriting' => 'Underwriting',
                                        'approved' => 'Approved',
                                        default => $state ? ucfirst(str_replace('_', ' ', $state)) : '-',
                                    }),
                            ]),
                    ]),

                Section::make('Assignment')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label('Assigned On')
                                    ->dateTime('d M Y h:i A'),

                                TextEntry::make('opens_count')
                                    ->label('Times Opened'),

                                TextEntry::make('last_opened_at')
                                    ->label('Last Opened')
                                    ->dateTime('d M Y h:i A')
                                    ->placeholder('Never'),
                            ]),
                    ]),

                Section::make('Remarks')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('remarks')
                            ->label('')
                            ->contained(true)
                            ->schema([
                                TextEntry::make('employee.emp_name')
                                    ->label('By')
                                    ->placeholder('-'),

                                TextEntry::make('created_at')
                                    ->label('Added On')
                                    ->dateTime('d M Y h:i A'),

                                TextEntry::make('remark')
                                    ->label('')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->visible(fn ($record) => $record->remarks()->exists()),

                Section::make('Follow Up History')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('customer.followUps')
                            ->label('')
                            ->contained(true)
                            ->schema(static::followUpEntrySchema())
                            ->columns(3),
                    ])
                    ->visible(fn ($record) => filled($record->customer_id) && $record->customer?->followUps()->exists()),

                Section::make('Follow Up History')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('aiCustomerRecord.followUps')
                            ->label('')
                            ->contained(true)
                            ->schema(static::followUpEntrySchema())
                            ->columns(3),
                    ])
                    ->visible(fn ($record) => filled($record->ai_customer_record_id) && $record->aiCustomerRecord?->followUps()->exists()),
            ]);
    }

    protected static function followUpEntrySchema(): array
    {
        return [
            TextEntry::make('created_at')
                ->label('Logged On')
                ->dateTime('d M Y h:i A'),

            TextEntry::make('follow_up_type')
                ->label('Type'),

            TextEntry::make('status')
                ->badge(),

            TextEntry::make('next_follow_up_date')
                ->label('Next Follow Up')
                ->dateTime('d M Y h:i A')
                ->placeholder('-'),

            TextEntry::make('remarks')
                ->columnSpanFull(),
        ];
    }
}
