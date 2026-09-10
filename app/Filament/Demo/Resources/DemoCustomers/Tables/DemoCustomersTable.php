<?php

namespace App\Filament\Demo\Resources\DemoCustomers\Tables;

use App\Models\Demo\DemoCustomer;
use App\Support\Portal\IndianFaker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DemoCustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee', 'lead'])->withCount('applications'))
            ->columns([
                TextColumn::make('customer_code')
                    ->label('Code')
                    ->searchable()
                    ->fontFamily('mono'),

                TextColumn::make('customer_name')
                    ->label('Name')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('mobile_no')
                    ->label('Mobile')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('company_name')
                    ->label('Employer')
                    ->limit(24)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('city')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('salary')
                    ->label('Salary')
                    ->money('INR', 100)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('eligible_loan_amount')
                    ->label('Eligible')
                    ->money('INR', 100)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('applications_count')
                    ->label('Apps')
                    ->alignEnd(),

                TextColumn::make('journey_status')
                    ->label('Journey')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'disbursed' => 'success',
                        'sanctioned', 'approved' => 'info',
                        'rejected' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => DemoCustomer::JOURNEY_STATUSES[$state] ?? $state)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('journey_status')
                    ->label('Journey')
                    ->options(DemoCustomer::JOURNEY_STATUSES),

                SelectFilter::make('eligibility_status')
                    ->label('Eligibility')
                    ->options([
                        'eligible' => 'Eligible',
                        'pending' => 'Pending',
                        'not_eligible' => 'Not Eligible',
                    ]),

                SelectFilter::make('city')
                    ->options(array_combine(IndianFaker::CITIES, IndianFaker::CITIES))
                    ->multiple(),

                SelectFilter::make('demo_employee_id')
                    ->label('Owner')
                    ->relationship('employee', 'name'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
