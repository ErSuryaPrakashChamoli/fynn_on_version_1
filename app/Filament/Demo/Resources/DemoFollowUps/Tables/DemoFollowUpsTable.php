<?php

namespace App\Filament\Demo\Resources\DemoFollowUps\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DemoFollowUpsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lead', 'customer', 'employee']))
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label('Scheduled')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                TextColumn::make('contact')
                    ->label('Contact')
                    ->state(fn ($record): string => $record->customer?->customer_name
                        ?? $record->lead?->customer_name
                        ?? '—')
                    ->weight('medium')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('lead', fn (Builder $lead) => $lead->where('customer_name', 'like', "%{$search}%"))
                        ->orWhereHas('customer', fn (Builder $customer) => $customer->where('customer_name', 'like', "%{$search}%"))),

                TextColumn::make('type')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->sortable(),

                TextColumn::make('employee.name')
                    ->label('Owner')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'missed' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->sortable(),

                TextColumn::make('outcome')
                    ->placeholder('—')
                    ->limit(28)
                    ->toggleable(),

                TextColumn::make('remarks')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'completed' => 'Completed',
                        'missed' => 'Missed',
                    ]),

                SelectFilter::make('type')
                    ->options([
                        'call' => 'Call',
                        'whatsapp' => 'WhatsApp',
                        'email' => 'Email',
                        'visit' => 'Visit',
                    ]),

                Filter::make('due_today')
                    ->label('Due today')
                    ->query(fn (Builder $query) => $query->whereDate('scheduled_at', today())),

                Filter::make('overdue')
                    ->label('Overdue')
                    ->query(fn (Builder $query) => $query
                        ->where('status', 'pending')
                        ->where('scheduled_at', '<', now())),
            ])
            ->defaultSort('scheduled_at', 'desc');
    }
}
