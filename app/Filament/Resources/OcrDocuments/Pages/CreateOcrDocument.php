<?php

namespace App\Filament\Resources\OcrDocuments\Pages;

use App\Filament\Resources\OcrDocuments\OcrDocumentResource;
use App\Jobs\ProcessOcrDocument;
use App\Models\OcrDocument;
use App\Services\CustomerDataImportService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateOcrDocument extends CreateRecord
{
    protected static string $resource = OcrDocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $path = $data['original_path'] ?? null;

        // storeFileNamesIn() keeps the name the file was uploaded with; the
        // stored path itself is a random name.
        $data['original_name'] = filled($data['original_name'] ?? null)
            ? $data['original_name']
            : ($path ? basename($path) : 'document');

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
        $data['title'] = filled($data['title'] ?? null)
            ? trim($data['title'])
            : Str::beforeLast($data['original_name'], '.');

        // The ticked columns become the template the document is read with.
        try {
            $data['schema_id'] = app(CustomerDataImportService::class)
                ->templateForDocument($data['title'], $data['columns'] ?? [], auth()->user())
                ->id;
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Check the columns')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }

        unset($data['columns']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->record instanceof OcrDocument) {
            ProcessOcrDocument::dispatchFor($this->record);
        }
    }
}
