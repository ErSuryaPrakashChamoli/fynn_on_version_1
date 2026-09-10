<?php

namespace App\Filament\Demo\Resources\DemoEmployees\Tables;

use App\Support\Portal\IndianFaker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DemoEmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('manager')->withCount(['leads', 'customers']))
            ->columns([
                TextColumn::make('emp_code')
                    ->label('Code')
                    ->searchable()
                    ->fontFamily('mono'),

                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('designation')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('department')
                    ->toggleable(),

                TextColumn::make('city')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('manager.name')
                    ->label('Reports to')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('leads_count')
                    ->label('Leads')
                    ->alignEnd(),

                TextColumn::make('customers_count')
                    ->label('Customers')
                    ->alignEnd(),

                TextColumn::make('monthly_target')
                    ->label('Target')
                    ->money('INR', 100)
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('designation')
                    ->options([
                        'Sales Executive' => 'Sales Executive',
                        'Senior Sales Executive' => 'Senior Sales Executive',
                        'Team Leader' => 'Team Leader',
                        'Manager' => 'Manager',
                    ]),

                SelectFilter::make('city')
                    ->options(array_combine(IndianFaker::CITIES, IndianFaker::CITIES))
                    ->multiple(),

                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->defaultSort('name');
    }
}
