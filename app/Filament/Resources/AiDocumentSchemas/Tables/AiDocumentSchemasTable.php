<?php

namespace App\Filament\Resources\AiDocumentSchemas\Tables;

use App\Models\AiDocumentSchema;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiDocumentSchemasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('Configuration')->searchable()->sortable(),
                TextColumn::make('description')->limit(40)->searchable()->sortable(),
                TextColumn::make('fields')->label('Columns')
                    ->state(fn (AiDocumentSchema $record) => count($record->getFieldDefinitions())),
                TextColumn::make('records_count')->label('Imported Records')
                    ->counts('records')
                    ->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                TextColumn::make('created_at')->dateTime('d M Y h:i A')->sortable(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
