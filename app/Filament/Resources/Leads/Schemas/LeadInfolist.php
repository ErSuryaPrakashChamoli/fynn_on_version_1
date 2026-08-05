<?php

namespace App\Filament\Resources\Leads\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Fieldset;

class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {


        return $schema
            ->components([

                Grid::make(12)
                    ->schema([

                        Fieldset::make('Lead Information')
                            ->columnSpan(12)
                            ->extraAttributes([
                                'class' => 'bg-blue-100 border-2 border-blue-400 rounded-xl p-5 shadow',
                            ])
                            ->schema([



                                        TextEntry::make('customer_name')
                                            ->label('Prospect Name'),

                                        TextEntry::make('mobile_no')
                                            ->label('Mobile Number'),

                                        TextEntry::make('email')
                                            ->label('Email Address'),

                                        TextEntry::make('pan_number')
                                            ->label('PAN Number'),

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




                            ]),

                        // Fieldset::make('Conversion Status')
                        //     ->columnSpan(6)
                        //     ->extraAttributes([
                        //         'class' => 'bg-green-100 border-2 border-green-400 rounded-xl p-5 shadow',
                        //     ])
                        //     ->schema([

                        //         TextEntry::make('is_converted')
                        //             ->label('Status')
                        //             ->badge()
                        //             ->color(fn($state) => $state ? 'success' : 'warning')
                        //             ->formatStateUsing(fn($state) => $state ? 'Converted' : 'Pending'),

                        //         TextEntry::make('convertedCustomer.application_no')
                        //             ->label('Application No')
                        //             ->icon('heroicon-o-document-text')
                        //             ->iconColor('success')
                        //             ->badge()
                        //             ->color('success')
                        //             ->placeholder('N/A')
                        //             ->copyable(),
                        //     ]),

                        // Fieldset::make('System Information')
                        //     ->columnSpanFull()
                        //     ->extraAttributes([
                        //         'class' => 'bg-yellow-100 border-2 border-yellow-400 rounded-xl p-5 shadow',
                        //     ])
                        //     ->schema([
                        //         TextEntry::make('created_at')
                        //             ->label('Created On')
                        //             ->dateTime()
                        //             ->icon('heroicon-o-calendar-days')
                        //             ->iconColor('amber')
                        //             ->extraAttributes(['class' => 'text-xs text-amber-950 font-medium']),

                        //         TextEntry::make('updated_at')
                        //             ->label('Last Updated')
                        //             ->dateTime()
                        //             ->icon('heroicon-o-arrow-path')
                        //             ->iconColor('amber')
                        //             ->extraAttributes(['class' => 'text-xs text-amber-950 font-medium']),
                        //     ]),

                    ])

            ]);
    }
}
