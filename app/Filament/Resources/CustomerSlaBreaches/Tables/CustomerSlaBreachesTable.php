<?php

namespace App\Filament\Resources\CustomerSlaBreaches\Tables;

use App\Enums\JourneyModule;
use App\Filament\Resources\JourneyTakeovers\JourneyTakeoverResource;
use App\Models\CustomerSlaBreach;
use App\Support\EmployeeOptions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomerSlaBreachesTable
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

                TextColumn::make('module')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => JourneyModule::tryFrom((string) $state)?->label() ?? (string) $state),

                TextColumn::make('stage_entered_at')
                    ->dateTime('d M Y h:i A')
                    ->label('In Stage Since')
                    ->sortable(),

                TextColumn::make('reminder_sent_at')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('escalated_at')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('Not escalated')
                    ->sortable()
                    ->color(fn (?string $state): string => $state ? 'danger' : 'gray'),

                TextColumn::make('escalatedTo.emp_name')
                    ->label('Escalated To')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('escalatedTo.emp_id')
                    ->label('Escalated To Emp ID')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->colors([
                        'danger' => CustomerSlaBreach::STATUS_OPEN,
                        'success' => CustomerSlaBreach::STATUS_RESOLVED,
                    ]),
            ])
            ->defaultSort('stage_entered_at')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        CustomerSlaBreach::STATUS_OPEN => 'Open',
                        CustomerSlaBreach::STATUS_RESOLVED => 'Resolved',
                    ])
                    ->default(CustomerSlaBreach::STATUS_OPEN),

                SelectFilter::make('module')
                    ->label('Module')
                    ->multiple()
                    ->options(collect(JourneyModule::cases())->mapWithKeys(fn (JourneyModule $module): array => [$module->value => $module->label()])->all()),

                SelectFilter::make('escalated_to_employee_id')
                    ->label('Escalated To')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo()),
            ])
            ->recordActions([
                Action::make('takeOver')
                    ->label('Take Over Journey')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->visible(fn (CustomerSlaBreach $record): bool => $record->status === CustomerSlaBreach::STATUS_OPEN)
                    ->url(fn (CustomerSlaBreach $record): string => JourneyTakeoverResource::getUrl('index')),

                Action::make('resolve')
                    ->label('Mark Resolved')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CustomerSlaBreach $record): bool => $record->status === CustomerSlaBreach::STATUS_OPEN)
                    ->action(function (CustomerSlaBreach $record): void {
                        $record->forceFill([
                            'status' => CustomerSlaBreach::STATUS_RESOLVED,
                            'resolved_at' => now(),
                        ])->save();

                        Notification::make()->success()->title('Breach marked resolved')->send();
                    }),
            ]);
    }
}
