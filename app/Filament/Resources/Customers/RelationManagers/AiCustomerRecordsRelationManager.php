<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AiCustomerRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'aiCustomerRecords';
    protected static ?string $title = 'AI Document Data';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('schema.name')->label('Configuration')->searchable(),
                TextColumn::make('document.original_name')->label('Source Document')->limit(35),
                TextColumn::make('status')->badge(),
                TextColumn::make('confidence_score')
                    ->label('Confidence')
                    ->formatStateUsing(fn ($state) => $state === null ? '-' : number_format((float) $state * 100, 1) . '%'),
                TextColumn::make('created_at')->dateTime('d M Y h:i A')->sortable(),
            ])
            ->headerActions([])
            ->recordActions([]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->check();
    }
}
