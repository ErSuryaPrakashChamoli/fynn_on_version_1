<?php

namespace App\Services\Ocr;

use App\Models\AiCustomerRecord;
use App\Models\OcrDocument;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OcrDocumentProcessor
{
    public function __construct(
        private readonly OcrFieldExtractionService $fieldExtractor,
        private readonly AiDocumentMappingService $mappingService,
        private readonly OcrTableExtractionService $tableExtractor,
    ) {}

    public function process(OcrDocument $document): void
    {
        $document->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists($document->original_path)) {
                throw new \RuntimeException(
                    'Original document was not found on the local disk: '
                        . $document->original_path
                );
            }

            $path = $disk->path($document->original_path);

            $result = app('laravel-ocr.parser')->parse($path, [
                'document_type' => $document->document_type ?: 'general',
                'use_ai_cleanup' => (bool) env(
                    'LARAVEL_OCR_AI_CLEANUP',
                    false
                ),
                'save_to_database' => false,
            ]);

            $text = (string) ($result->text ?? '');
            $confidence = $result->confidence ?? null;
            $metadata = is_array($result->metadata ?? null)
                ? $result->metadata
                : [];

            $pageCount = $metadata['pdf_pages']
                ?? $metadata['page_count']
                ?? null;

            $pageData = is_array($metadata['pages'] ?? null)
                ? $metadata['pages']
                : [];

            /*
             * This general pass is fast, but it is not the real work for a
             * schema-driven document — the (much slower) table extraction
             * and customer-record mapping below still has to run. Do NOT
             * mark the document 'completed' or stamp 'processed_at' here,
             * or status/timestamp will lie about being done while the
             * multi-page table OCR is still running in the background,
             * which is especially misleading on large, many-page files.
             */
            $document->update([
                'ocr_text' => $text,
                'extracted_data' => [
                    'mode' => $document->schema_id
                        ? 'table'
                        : 'fields',
                ],
                'page_data' => $pageData,
                'confidence_score' => is_numeric($confidence)
                    ? $confidence
                    : null,
                'page_count' => is_numeric($pageCount)
                    ? (int) $pageCount
                    : null,
                'error_message' => null,
            ]);

            if ($document->schema) {

                $table = $this->tableExtractor->extract(
                    $path,
                    $document->schema
                );

                $document->update([
                    'extracted_data' => [
                        'mode' => 'table',
                        'headers' => $table['headers'],
                        'rows' => $table['rows'],
                    ],
                ]);

                /*
                 * STEP 2A
                 *
                 * Re-processing the same document must NOT
                 * create duplicate customer records.
                 */
                // DB::transaction(function () use (
                //     $document,
                //     $table
                // ) {

                //     AiCustomerRecord::where(
                //         'ocr_document_id',
                //         $document->id
                //     )->delete();

                //     $this->mappingService->mapAndSaveRows(
                //         $document->fresh('schema'),
                //         $table['rows'],
                //     );
                // });

                $this->mappingService->mapAndSaveRows(
                    $document->fresh('schema'),
                    $table['rows'],
                );
            } else {

                $fields = $this->fieldExtractor->extract(
                    $text,
                    $document->document_type
                );

                $this->mappingService->mapAndSave(
                    $document->fresh('schema'),
                    $fields,
                    is_numeric($confidence)
                        ? (float) $confidence
                        : null,
                );
            }

            $document->update([
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            /*
             * A large scan can take a long time even fully optimized —
             * the uploader shouldn't have to keep the tab open watching a
             * status badge to find out it's done. This lands in Filament's
             * notification bell (requires ->databaseNotifications() on the
             * panel) rather than a one-off flash message, so it's still
             * there whenever they next check, on any page.
             */
            if ($document->uploader) {
                $rows = $document->extracted_data['rows'] ?? null;

                Notification::make()
                    ->title('OCR processing completed')
                    ->body($document->original_name . ' finished processing' . (is_array($rows) ? ' — ' . count($rows) . ' row(s) extracted.' : '.'))
                    ->success()
                    ->sendToDatabase($document->uploader);
            }
        } catch (\Throwable $e) {

            Log::error(
                'OCR document processing failed',
                [
                    'ocr_document_id' => $document->id,
                    'message' => $e->getMessage(),
                ]
            );

            $document->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            throw $e;
        }
    }
}
