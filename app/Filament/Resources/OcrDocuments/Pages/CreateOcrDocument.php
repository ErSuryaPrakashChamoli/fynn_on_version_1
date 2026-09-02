<?php

namespace App\Filament\Resources\OcrDocuments\Pages;

use App\Filament\Resources\OcrDocuments\OcrDocumentResource;
use App\Jobs\ProcessOcrDocument;
use App\Models\OcrDocument;
use Illuminate\Support\Facades\Storage;
use Filament\Resources\Pages\CreateRecord;

class CreateOcrDocument extends CreateRecord
{
    protected static string $resource = OcrDocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $path = $data['original_path'] ?? null;

        $data['original_name'] = $path ? basename($path) : 'document';

        // FileUpload uses the configured Laravel disk. In Laravel 12/13 the
        // local disk normally points to storage/app/private, so never build
        // the filesystem path manually with storage_path('app/...').
        if ($path && Storage::disk('local')->exists($path)) {
            $absolutePath = Storage::disk('local')->path($path);
            $data['mime_type'] = mime_content_type($absolutePath) ?: null;
            $data['file_size'] = filesize($absolutePath) ?: null;
        } else {
            $data['mime_type'] = null;
            $data['file_size'] = null;
        }
        $data['uploaded_by'] = auth()->id();
        $data['status'] = 'pending';
        $data['title'] ??= $data['original_name'];

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->record instanceof OcrDocument) {
            ProcessOcrDocument::dispatchFor($this->record);
        }
    }
}
