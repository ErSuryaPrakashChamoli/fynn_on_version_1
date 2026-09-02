<?php

namespace App\Jobs;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrDocumentProcessor;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessOcrDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /*
     * Under the previous 'sync' queue driver this timeout was never
     * enforced. On a real queue (with pcntl available) Laravel does
     * enforce it, and the multi-pass Tesseract table extraction runs
     * roughly ~15-20s per page — a large, many-page scan can legitimately
     * take well past the old 5-minute value. 3600s gives large files room
     * to finish; Supervisor's stopwaitsecs must be >= this value.
     */
    public int $timeout = 3600;

    /*
     * A single huge multi-page scan (seen in practice: 180+ pages) can
     * occupy every worker for tens of minutes per attempt, including
     * retries. With a small, fixed worker pool, that starves small/simple
     * documents of any worker slot even though they'd finish in seconds —
     * the queue is FIFO per worker, so a tiny file dispatched after a
     * monster one just waits. Files at or under this size are routed to
     * the separate "quick" queue instead of "default", so they always
     * have a dedicated worker lane regardless of what large documents are
     * still grinding through the (much heavier) coordinate-based table
     * pipeline. See deploy/supervisor/fynn-ocr-worker.conf for the worker
     * that listens on "quick".
     */
    private const QUICK_QUEUE_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(public int $ocrDocumentId) {}

    public static function dispatchFor(OcrDocument $document): void
    {
        $isQuick = $document->file_size !== null
            && $document->file_size <= self::QUICK_QUEUE_MAX_BYTES;

        static::dispatch($document->id)->onQueue($isQuick ? 'quick' : 'default');
    }

    public function handle(OcrDocumentProcessor $processor): void
    {
        /*
         * smalot/pdfparser loads the whole PDF into memory to extract text,
         * and a large scanned PDF (uploads are allowed up to 500MB) can blow
         * past the default CLI memory_limit (768M). That failure surfaces as
         * an uncatchable "Allowed memory size exhausted" fatal error deep in
         * vendor code — it kills the worker process outright before any
         * catch(\Throwable) in OcrDocumentProcessor ever runs, so raise the
         * ceiling up front instead of trying to catch our way out of it.
         */
        ini_set('memory_limit', env('OCR_JOB_MEMORY_LIMIT', '4096M'));

        $document = OcrDocument::find($this->ocrDocumentId);

        // if (! $document || $document->status === 'completed') {
        //     return;
        // }
        if (! $document) {
            return;
        }

        $processor->process($document);
    }

    public function failed(Throwable $exception): void
    {
        $document = OcrDocument::with('uploader')->find($this->ocrDocumentId);

        $document?->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);

        /*
         * This is the one place Laravel calls when a job has definitively
         * failed for good (retries exhausted) — OcrDocumentProcessor's own
         * catch block also sets status=failed, but that can still be
         * retried afterwards, so notifying there would fire on attempts
         * that go on to succeed or get retried again. Mirrors the
         * completion notification in OcrDocumentProcessor::process() so
         * the uploader hears about a large scan finishing either way
         * without having to keep the tab open.
         */
        if ($document?->uploader) {
            Notification::make()
                ->title('OCR processing failed')
                ->body($document->original_name . ': ' . $exception->getMessage())
                ->danger()
                ->sendToDatabase($document->uploader);
        }
    }
}
