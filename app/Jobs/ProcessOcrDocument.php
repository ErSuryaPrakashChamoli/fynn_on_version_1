<?php

namespace App\Jobs;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrDocumentProcessor;
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

    public int $timeout = 300;

    public function __construct(public int $ocrDocumentId) {}

    public function handle(OcrDocumentProcessor $processor): void
    {
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
        OcrDocument::whereKey($this->ocrDocumentId)->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }
}
