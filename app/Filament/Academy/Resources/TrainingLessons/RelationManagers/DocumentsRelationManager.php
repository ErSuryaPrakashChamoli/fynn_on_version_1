<?php

namespace App\Filament\Academy\Resources\TrainingLessons\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lesson material.
 *
 * The upload targets the private 'local' disk with private visibility,
 * NOT the app's default 'public' disk — a training document must never
 * be reachable at a guessable /storage/... URL. Downloads go through
 * TrainingDocumentDownloadController, which checks enrollment first.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Material';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            FileUpload::make('path')
                ->label('File')
                ->disk('local')
                ->directory('academy/documents')
                ->visibility('private')
                ->acceptedFileTypes([
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->maxSize(20480)
                ->required()
                ->storeFileNamesIn('original_name')
                ->columnSpanFull(),

            Toggle::make('is_downloadable')
                ->label('Trainees may download')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('original_name')
                    ->label('File')
                    ->limit(32)
                    ->placeholder('—'),

                TextColumn::make('size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (int $state): string => $state > 0
                        ? number_format($state / 1024, 0).' KB'
                        : '—')
                    ->alignEnd(),

                IconColumn::make('is_downloadable')
                    ->label('Downloadable')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        // Stamp the disk explicitly so a document can
                        // never be recorded against the public one.
                        $data['disk'] = 'local';

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
