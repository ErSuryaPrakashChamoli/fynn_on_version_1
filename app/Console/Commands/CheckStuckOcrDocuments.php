<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStuckOcrDocuments extends Command
{
    protected $signature = 'ocr:check-stuck';

    protected $description = 'Log a warning for OCR documents stuck in processing longer than expected (worker likely died without reporting failure)';

    /*
     * App\Jobs\ProcessOcrDocument::$timeout is 3600s and Laravel enforces
     * it via pcntl, so a job that is merely slow already self-terminates
     * (and marks the document 'failed') well before this. A document still
     * sitting in 'processing' past this threshold means something skipped
     * that path entirely — e.g. the worker process itself was OOM-killed
     * or the host restarted — not just a large file taking a while.
     */
    private const STUCK_AFTER_MINUTES = 120;

    public function handle(): int
    {
        $stuck = OcrDocument::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->get(['id', 'title', 'original_name', 'updated_at']);

        if ($stuck->isEmpty()) {
            $this->info('No stuck OCR documents.');

            return self::SUCCESS;
        }

        Log::warning('OCR documents stuck in processing', [
            'threshold_minutes' => self::STUCK_AFTER_MINUTES,
            'documents' => $stuck->map(fn (OcrDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'original_name' => $document->original_name,
                'stuck_since' => $document->updated_at?->toDateTimeString(),
            ])->all(),
        ]);

        $this->warn($stuck->count() . ' OCR document(s) stuck in processing past ' . self::STUCK_AFTER_MINUTES . ' minutes — logged.');

        return self::SUCCESS;
    }
}
