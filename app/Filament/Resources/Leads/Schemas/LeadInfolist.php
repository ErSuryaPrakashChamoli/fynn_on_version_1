<?php

namespace App\Filament\Resources\Leads\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                // 1. Lead Details Section (Full Width inside Modal)
                Section::make('Lead Information')
                    ->description('Personal and financial information of the prospect')
                    ->icon('heroicon-o-user-circle')
                    ->schema([

                        TextEntry::make('customer_name')
                            ->label('Prospect Name')
                            ->icon('heroicon-o-user')
                            ->weight('bold')
                            ->copyable()
                            ->extraAttributes(['class' => 'text-primary-600 dark:text-primary-400']),

                        TextEntry::make('mobile_no')
                            ->label('Mobile Number')
                            ->icon('heroicon-o-phone')
                            ->copyable(),

                        TextEntry::make('email')
                            ->label('Email Address')
                            ->icon('heroicon-o-envelope')
                            ->copyable(),

                        TextEntry::make('pan_number')
                            ->label('PAN Number')
                            ->icon('heroicon-o-identification')
                            ->badge()
                            ->color('gray')
                            ->copyable(),

                        TextEntry::make('current_location')
                            ->icon('heroicon-o-map-pin'),

                        TextEntry::make('job_location')
                            ->icon('heroicon-o-briefcase'),

                        TextEntry::make('salary')
                            ->money('INR')
                            ->icon('heroicon-o-banknotes')
                            ->weight('bold')
                            ->extraAttributes(['class' => 'text-emerald-600 font-mono dark:text-emerald-400']),

                        TextEntry::make('employee.emp_name')
                            ->label('Assigned Employee')
                            ->icon('heroicon-o-user-group')
                            ->placeholder('Unassigned'),

                        TextEntry::make('follow_up_date')
                            ->date()
                            ->icon('heroicon-o-calendar'),

                        TextEntry::make('follow_up_type')
                            ->badge()
                            ->color('info')
                            ->icon('heroicon-o-tag'),

                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match (strtolower($state)) {
                                'new', 'pending' => 'warning',
                                'contacted', 'in_progress' => 'info',
                                'converted', 'approved' => 'success',
                                'rejected', 'lost' => 'danger',
                                default => 'gray',
                            }),

                        TextEntry::make('next_follow_up_date')
                            ->date()
                            ->icon('heroicon-o-clock')
                            ->weight('bold')
                            ->extraAttributes(['class' => 'text-amber-600 dark:text-amber-400']),

                        TextEntry::make('remarks')
                            ->columnSpanFull()
                            ->markdown()
                            ->placeholder('No remarks available')
                            ->extraAttributes(['class' => 'p-3 bg-gray-50 rounded-lg dark:bg-gray-800/50 border border-gray-100 dark:border-gray-700/50']),

                    ])
                    ->columns(2)
                    ->collapsible(),

                // 2. Conversion Section
                Section::make('Conversion Status')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->schema([

                        TextEntry::make('is_converted')
                            ->label('Status')
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'warning')
                            ->formatStateUsing(fn ($state) => $state ? 'Converted' : 'Pending'),

                        TextEntry::make('convertedCustomer.application_no')
                            ->label('Application No')
                            ->icon('heroicon-o-document-text')
                            ->badge()
                            ->color('success')
                            ->placeholder('N/A')
                            ->copyable(),

                    ])
                    ->columns(2)
                    ->collapsible(),

                // 3. System Meta Section
                Section::make('System Information')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->schema([

                        TextEntry::make('created_at')
                            ->label('Created On')
                            ->dateTime()
                            ->icon('heroicon-o-calendar-days')
                            ->extraAttributes(['class' => 'text-xs text-gray-500']),

                        TextEntry::make('updated_at')
                            ->label('Last Updated')
                            ->dateTime()
                            ->icon('heroicon-o-arrow-path')
                            ->extraAttributes(['class' => 'text-xs text-gray-500']),

                    ])
                    ->columns(2)
                    ->collapsible(),

            ]);
    }
}
