<?php

namespace App\Filament\Resources\AiDocumentSchemas\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiDocumentSchemaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Import Configuration')
                ->description('Define the columns that your final AI Customer Data table should contain.')
                ->schema([
                    TextInput::make('name')
                        ->label('Configuration Name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('description')
                        ->label('Description')
                        ->maxLength(255),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ])
                ->columns(2),

            Section::make('Define Columns')
                ->description('Each row becomes one flexible column in the imported customer data.')
                ->schema([
                    Repeater::make('fields')
                        ->label('Columns')
                        ->schema([
                            TextInput::make('key')
                                ->label('Field Key')
                                ->helperText('Unique key, e.g. customer_name or loan_amount.')
                                ->required()
                                ->regex('/^[a-z][a-z0-9_]*$/')
                                ->maxLength(100),
                            TextInput::make('label')
                                ->label('Column Name')
                                ->required()
                                ->maxLength(150),
                            TagsInput::make('aliases')
                                ->label('Document Names / Aliases')
                                ->placeholder('Applicant Name')
                                ->helperText('Names that AI/OCR may use for this field.'),
                            TextInput::make('type')
                                ->label('Data Type')
                                ->default('text')
                                ->datalist(['text', 'number', 'decimal', 'date', 'mobile', 'pan', 'email', 'long_text'])
                                ->required(),
                            Toggle::make('required')
                                ->label('Required')
                                ->default(false),
                        ])
                        ->columns(2)
                        ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['key'] ?? null)
                        ->defaultItems(1)
                        ->addActionLabel('Add Column')
                        ->reorderable()
                        ->collapsible()
                        ->required()
                        ->minItems(1),
                ]),
        ]);
    }
}
