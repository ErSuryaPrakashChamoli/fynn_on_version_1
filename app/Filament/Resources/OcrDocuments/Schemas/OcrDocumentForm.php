<?php

namespace App\Filament\Resources\OcrDocuments\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OcrDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Document Upload')
                ->schema([
                    FileUpload::make('original_path')
                        ->label('PDF / Image')
                        ->disk('local')
                        ->directory('ocr-documents')
                        ->acceptedFileTypes([
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                            'image/tiff',
                        ])
                        ->maxSize(512000)
                        ->required()
                        ->downloadable()
                        ->openable(),

                    TextInput::make('original_name')
                        ->label('File Name')
                        ->disabled()
                        ->dehydrated(false),

                    Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'customer_name')
                        ->searchable()
                        ->preload(),

                    Select::make('schema_id')
                        ->label('Data Template')
                        ->relationship('schema', 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Select the Data Template that defines the columns you want to extract from this document.')
                        ->native(false),

                    Select::make('document_type')
                        ->label('Document Type')
                        ->options([
                            'customer_form' => 'Customer Form',
                            'kyc' => 'KYC',
                            'pan' => 'PAN',
                            'aadhaar' => 'Aadhaar',
                            'salary_slip' => 'Salary Slip',
                            'bank_statement' => 'Bank Statement',
                            'sanction_letter' => 'Sanction Letter',
                            'other' => 'Other',
                        ])
                        ->searchable(),

                    TextInput::make('title')
                        ->label('Title')
                        ->maxLength(255),
                ])
                ->columns(2),
        ]);
    }
}
