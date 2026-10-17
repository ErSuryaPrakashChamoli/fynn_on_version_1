<?php

namespace App\Services\Cibil;

use App\Enums\CibilPdfStatus;
use App\Models\CibilReport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saves the PDF of a PDF request to private storage — from the inline
 * base64 the provider may send, or by fetching its signed link once, right
 * away. Only a real PDF within the size limit is kept. A link that is
 * refused or gone is treated as expired (a new PDF request is needed); one
 * that merely could not be reached is reported back so the encrypted link
 * can be tried once more. The link itself is never logged.
 */
class CibilPdfStore
{
    public function store(CibilReport $report, ?string $base64, ?string $link): CibilStoredPdf
    {
        if ($base64 !== null) {
            $base64 = (string) preg_replace(['/^data:application\/pdf;base64,/i', '/\s+/'], '', $base64);
            $contents = base64_decode($base64, true);

            if (is_string($contents)) {
                $stored = $this->save($report, $contents);

                if ($stored->status === CibilPdfStatus::Available || $link === null) {
                    return $stored;
                }
            } elseif ($link === null) {
                return new CibilStoredPdf(CibilPdfStatus::Invalid);
            }
        }

        return $link !== null
            ? $this->download($report, $link)
            : new CibilStoredPdf(CibilPdfStatus::Missing);
    }

    public function download(CibilReport $report, string $link): CibilStoredPdf
    {
        if (! str_starts_with(strtolower($link), 'https://')) {
            return new CibilStoredPdf(CibilPdfStatus::Invalid);
        }

        try {
            $response = CibilHttp::quietly(fn (): Response => Http::timeout((int) config('cibil.pdf.download_timeout', 30))
                ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])
                ->get($link));
        } catch (ConnectionException) {
            Log::warning('CIBIL PDF could not be downloaded: the provider link was unreachable.', ['cibil_report_id' => $report->getKey()]);

            return new CibilStoredPdf(CibilPdfStatus::DownloadFailed, keepLink: true);
        }

        if (in_array($response->status(), [401, 403, 404, 410], true)) {
            return new CibilStoredPdf(CibilPdfStatus::Expired);
        }

        if (! $response->successful()) {
            Log::warning('CIBIL PDF could not be downloaded.', ['cibil_report_id' => $report->getKey(), 'status' => $response->status()]);

            return new CibilStoredPdf(CibilPdfStatus::DownloadFailed, keepLink: true);
        }

        if ((int) $response->header('Content-Length') > $this->maxBytes()) {
            return new CibilStoredPdf(CibilPdfStatus::Invalid);
        }

        return $this->save($report, $response->body());
    }

    public function disk(): string
    {
        $disk = (string) config('cibil.pdf.disk', 'local');

        // Credit reports never go to a publicly served disk.
        return $disk === 'public' || config("filesystems.disks.{$disk}.visibility") === 'public' ? 'local' : $disk;
    }

    private function save(CibilReport $report, string $contents): CibilStoredPdf
    {
        if ($contents === '' || strlen($contents) > $this->maxBytes() || ! str_starts_with($contents, '%PDF-')) {
            return new CibilStoredPdf(CibilPdfStatus::Invalid);
        }

        $path = trim((string) config('cibil.pdf.directory', 'cibil-reports'), '/').'/'.$report->getKey().'/'.Str::uuid().'.pdf';

        Storage::disk($this->disk())->put($path, $contents);

        return new CibilStoredPdf(CibilPdfStatus::Available, $path);
    }

    private function maxBytes(): int
    {
        return (int) config('cibil.pdf.max_bytes', 15 * 1024 * 1024);
    }
}
