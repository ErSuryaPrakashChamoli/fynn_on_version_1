<?php

namespace App\Filament\Resources\OcrDocuments\Pages;

use App\Filament\Resources\OcrDocuments\OcrDocumentResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ViewOcrDocument extends ViewRecord
{
    protected static string $resource = OcrDocumentResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Document')
                ->schema([
                    View::make('filament.ocr-document-viewer')
                        ->viewData(['document' => $this->record])
                        ->columnSpanFull(),
                ]),

            Section::make('Extraction')
                ->schema([
                    TextEntry::make('status')->badge(),
                    TextEntry::make('document_type')->label('Document Type'),
                    TextEntry::make('schema.name')->label('Data Template'),
                    TextEntry::make('ai_customer_records_count')->label('Rows Extracted')->state(fn ($record) => $record->aiCustomerRecords()->count()),
                    TextEntry::make('page_count')->label('Pages'),
                    TextEntry::make('formatted_confidence')->label('OCR Confidence'),
                    TextEntry::make('ocr_text')->label('Raw OCR Text')->prose()->columnSpanFull(),
                ])
                ->columns(4),

            Section::make('Structured Customer Data')
                ->schema([
                    View::make('filament.ocr-document-data-table')
                        ->viewData(['document' => $this->record])
                        ->columnSpanFull(),
                ]),

            Section::make('Processing Error')
                ->schema([
                    TextEntry::make('error_message')->label('Error')->columnSpanFull(),
                ])
                ->visible(fn ($record) => filled($record->error_message)),
        ]);
    }
}
