<?php

namespace App\Filament\Resources\CustomerJourneyAudits\Tables;

use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Support\EmployeeOptions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomerJourneyAuditsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.application_no')
                    ->label('Application No')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('journey_stage')
                    ->label('Stage')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('module')
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => JourneyModule::tryFrom((string) $state)?->label() ?? '—'),

                TextColumn::make('action')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('originalOwner.emp_name')
                    ->label('Original Owner')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('originalOwner.emp_id')
                    ->label('Original Owner Emp ID')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('actingEmployee.emp_name')
                    ->label('Acting Employee')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('actingEmployee.emp_id')
                    ->label('Acting Emp ID')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('access_type')
                    ->label('Access Type')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (JourneyAccessType $state): string => $state->label())
                    ->color(fn (JourneyAccessType $state): string => match ($state) {
                        JourneyAccessType::Normal => 'gray',
                        JourneyAccessType::TemporaryDelegation => 'info',
                        JourneyAccessType::EmergencyTakeover, JourneyAccessType::Escalation => 'danger',
                        JourneyAccessType::AdminOrganisationWideHandover => 'warning',
                        JourneyAccessType::PermanentReassignment => 'warning',
                    }),

                TextColumn::make('case_type')
                    ->label('Case Type')
                    ->badge()
                    ->placeholder('—')
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'new_customer' => 'New Customer',
                        'existing_customer' => 'Existing Customer',
                        default => '—',
                    }),

                TextColumn::make('is_admin_override')
                    ->label('Admin Override')
                    ->badge()
                    ->sortable()
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reason')
                    ->limit(40)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('performedBy.name')
                    ->label('Performed By')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('performed_at')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),
            ])
            ->defaultSort('performed_at', 'desc')
            ->filters([
                SelectFilter::make('access_type')
                    ->label('Access Type')
                    ->multiple()
                    ->options(collect(JourneyAccessType::cases())->mapWithKeys(fn (JourneyAccessType $type): array => [$type->value => $type->label()])->all()),

                SelectFilter::make('module')
                    ->label('Module')
                    ->multiple()
                    ->options(collect(JourneyModule::cases())->mapWithKeys(fn (JourneyModule $module): array => [$module->value => $module->label()])->all()),

                SelectFilter::make('case_type')
                    ->label('Case Type')
                    ->options([
                        'new_customer' => 'New Customer',
                        'existing_customer' => 'Existing Customer',
                    ]),

                SelectFilter::make('acting_employee_id')
                    ->label('Acting Employee')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo()),
            ]);
    }
}
