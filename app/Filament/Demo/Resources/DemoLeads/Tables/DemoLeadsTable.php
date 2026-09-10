<?php

namespace App\Filament\Demo\Resources\DemoLeads\Tables;

use App\Models\Demo\DemoLead;
use App\Support\Portal\IndianFaker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DemoLeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee', 'bank', 'loanProduct']))
            ->columns([
                TextColumn::make('lead_code')
                    ->label('Lead')
                    ->searchable()
                    ->fontFamily('mono')
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Name')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('mobile_no')
                    ->label('Mobile')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('city')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('loanProduct.name')
                    ->label('Product')
                    ->badge()
                    ->color('info'),

                TextColumn::make('requested_amount')
                    ->label('Requested')
                    ->money('INR', 100)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('employee.name')
                    ->label('Owner')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'converted' => 'success',
                        'qualified' => 'info',
                        'contacted' => 'warning',
                        'not_interested', 'invalid' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => DemoLead::STATUSES[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('follow_up_date')
                    ->label('Follow-up')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(DemoLead::STATUSES),

                SelectFilter::make('city')
                    ->options(array_combine(IndianFaker::CITIES, IndianFaker::CITIES))
                    ->multiple(),

                SelectFilter::make('source')
                    ->options(array_combine(IndianFaker::LEAD_SOURCES, array_map(
                        fn (string $source): string => str($source)->headline()->toString(),
                        IndianFaker::LEAD_SOURCES,
                    ))),

                SelectFilter::make('demo_employee_id')
                    ->label('Owner')
                    ->relationship('employee', 'name'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
