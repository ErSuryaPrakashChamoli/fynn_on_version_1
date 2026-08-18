<?php

namespace App\Filament\Resources\CustomerSettlements\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SettlementHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'histories';
    protected static ?string $title = 'Change History';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date / Time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('action')
                    ->badge()
                    ->searchable(),
                TextColumn::make('field_name')
                    ->label('Field')
                    ->formatStateUsing(fn (?string $state) => $state ? str($state)->replace('_', ' ')->title() : '-'),
                TextColumn::make('old_value')
                    ->label('Old Value')
                    ->limit(60),
                TextColumn::make('new_value')
                    ->label('New Value')
                    ->limit(60),
                TextColumn::make('source')->badge(),
                TextColumn::make('performedBy.name')->label('Changed By'),
                TextColumn::make('reason')->limit(80),
            ])
            ->headerActions([])
            ->recordActions([]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->hasAnyRole(['Admin', 'Accounts']) ?? false;
    }
}
