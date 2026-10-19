<?php

namespace App\Services\Cibil;

use App\Enums\CibilPdfStatus;
use App\Enums\CreditBureau;
use App\Models\CibilReport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Saves the PDF of a PDF request to private storage — from the inline
 * base64 the provider may send, or by fetching its signed link once, right
 * away. Only a real PDF (by its %PDF- signature, never an HTML error page)
 * within the size limit is kept, with its size and SHA-256 checksum. A link
 * that is refused or gone is treated as expired (a new PDF request is
 * needed); one that merely could not be reached is reported back so the
 * encrypted link can be tried once more. The link itself is never logged.
 *
 * Against SSRF, a bureau with a host allowlist (config cibil.<bureau>.
 * pdf_hosts — Experian's is Surepass's S3 bucket) is only ever fetched from
 * those hosts, and redirects are not followed for it. A bureau without one
 * keeps the original rule: any https link, at most three https redirects.
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

        $allowedHosts = $this->allowedHosts($report->bureau);
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));

        if ($allowedHosts !== [] && ! self::hostAllowed($host, $allowedHosts)) {
            Log::warning('CIBIL PDF link not fetched: its host is not on the allowlist.', ['cibil_report_id' => $report->getKey(), 'host' => $host]);

            return new CibilStoredPdf(CibilPdfStatus::Blocked);
        }

        try {
            $response = CibilHttp::quietly(fn (): Response => Http::timeout((int) config('cibil.pdf.download_timeout', 30))
                ->withOptions(['allow_redirects' => $allowedHosts === [] ? ['max' => 3, 'protocols' => ['https']] : false])
                ->get($link));
        } catch (ConnectionException) {
            Log::warning('CIBIL PDF could not be downloaded: the provider link was unreachable.', ['cibil_report_id' => $report->getKey()]);

            return new CibilStoredPdf(CibilPdfStatus::DownloadFailed, keepLink: true);
        }

        // An allowlisted link that redirects elsewhere is not followed.
        if ($allowedHosts !== [] && $response->redirect()) {
            Log::warning('CIBIL PDF link not fetched: it redirected.', ['cibil_report_id' => $report->getKey(), 'status' => $response->status()]);

            return new CibilStoredPdf(CibilPdfStatus::Blocked);
        }

        if (in_array($response->status(), [401, 403, 404, 410], true)) {
            return new CibilStoredPdf(CibilPdfStatus::Expired);
        }

        if (! $response->successful()) {
            Log::warning('CIBIL PDF could not be downloaded.', ['cibil_report_id' => $report->getKey(), 'status' => $response->status()]);

            return new CibilStoredPdf(CibilPdfStatus::DownloadFailed, keepLink: true);
        }

        if ((int) $response->header('Content-Length') > $this->maxBytes()
            || str_contains(strtolower($response->header('Content-Type')), 'html')) {
            return new CibilStoredPdf(CibilPdfStatus::Invalid);
        }

        return $this->save($report, $response->body());
    }

    /**
     * The hosts a bureau's PDF links may be fetched from; empty means any
     * https host (the original CIBIL rule).
     *
     * @return list<string>
     */
    public function allowedHosts(CreditBureau $bureau): array
    {
        $hosts = $bureau->config()['pdf_hosts'] ?? [];
        $hosts = is_string($hosts) ? explode(',', $hosts) : (array) $hosts;

        return array_values(array_filter(array_map(fn (mixed $host): string => strtolower(trim((string) $host)), $hosts)));
    }

    /**
     * An entry matches its exact host; an entry starting with a dot
     * (".example.com") matches any subdomain of it.
     *
     * @param  list<string>  $allowed
     */
    private static function hostAllowed(string $host, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            if ($host === $entry || (str_starts_with($entry, '.') && str_ends_with($host, $entry))) {
                return true;
            }
        }

        return false;
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

        if (! Storage::disk($this->disk())->put($path, $contents)) {
            throw new RuntimeException('The PDF could not be written to private storage.');
        }

        return new CibilStoredPdf(CibilPdfStatus::Available, $path, size: strlen($contents), checksum: hash('sha256', $contents));
    }

    private function maxBytes(): int
    {
        return (int) config('cibil.pdf.max_bytes', 15 * 1024 * 1024);
    }
}
