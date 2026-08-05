<?php

namespace App\Filament\Resources\Leads\Schemas;

// use Filament\Infolists\Components\Section;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Lead Details')
                    ->schema([

                        TextEntry::make('customer_name')
                            ->label('Prospect Name'),

                        TextEntry::make('mobile_no'),

                        TextEntry::make('email'),

                        TextEntry::make('pan_number'),

                        TextEntry::make('current_location'),

                        TextEntry::make('job_location'),

                        TextEntry::make('salary')
                            ->money('INR'),

                        TextEntry::make('employee.emp_name')
                            ->label('Assigned Employee'),

                        TextEntry::make('follow_up_date')
                            ->date(),

                        TextEntry::make('follow_up_type'),

                        TextEntry::make('status')
                            ->badge(),

                        TextEntry::make('next_follow_up_date')
                            ->date(),

                        TextEntry::make('remarks')
                            ->columnSpanFull(),

                    ])
                    ->columns(2),

                Section::make('Conversion')
                    ->schema([

                        TextEntry::make('is_converted')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state ? 'Converted' : 'Pending'),

                        TextEntry::make('convertedCustomer.application_no')
                            ->label('Application No'),

                    ])
                    ->columns(2),

                Section::make('System')
                    ->schema([

                        TextEntry::make('created_at')
                            ->dateTime(),

                        TextEntry::make('updated_at')
                            ->dateTime(),

                    ])
                    ->columns(2),

            ]);
    }
}
