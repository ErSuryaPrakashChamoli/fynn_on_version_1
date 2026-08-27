<?php

namespace App\Filament\Resources\CustomerJourneyAudits\Tables;

use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
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
                    ->searchable(),

                TextColumn::make('customer.application_no')
                    ->label('Application No'),

                TextColumn::make('journey_stage')
                    ->label('Stage'),

                TextColumn::make('module')
                    ->formatStateUsing(fn (?string $state): string => JourneyModule::tryFrom((string) $state)?->label() ?? '—'),

                TextColumn::make('action'),

                TextColumn::make('originalOwner.emp_name')
                    ->label('Original Owner'),

                TextColumn::make('actingEmployee.emp_name')
                    ->label('Acting Employee'),

                TextColumn::make('access_type')
                    ->label('Access Type')
                    ->badge()
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
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'new_customer' => 'New Customer',
                        'existing_customer' => 'Existing Customer',
                        default => '—',
                    }),

                TextColumn::make('is_admin_override')
                    ->label('Admin Override')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reason')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('performedBy.name')
                    ->label('Performed By'),

                TextColumn::make('performed_at')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),
            ])
            ->defaultSort('performed_at', 'desc')
            ->filters([
                SelectFilter::make('access_type')
                    ->label('Access Type')
                    ->options(collect(JourneyAccessType::cases())->mapWithKeys(fn (JourneyAccessType $type): array => [$type->value => $type->label()])->all()),
            ]);
    }
}
