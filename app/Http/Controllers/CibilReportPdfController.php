<?php

namespace App\Http\Controllers;

use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilPdfStore;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CIBIL report's saved PDF from private storage. Only someone who
 * can see the report and holds the PDF permission gets it — a report they
 * cannot see is answered as not found — and every opening is written to
 * the CIBIL audit log. The provider's signed link is never handed out.
 */
class CibilReportPdfController extends Controller
{
    public function __invoke(Request $request, CibilReport $cibilReport, CibilReportService $service, CibilPdfStore $store): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $service->canView($cibilReport, $user), 404);
        abort_unless(CibilAccess::canDownloadPdf($user), 403);
        abort_unless($cibilReport->hasPdf(), 404);

        $disk = Storage::disk($store->disk());

        abort_unless($disk->exists((string) $cibilReport->pdf_path), 404);

        $service->recordPdfAccess($cibilReport, $user);

        return $disk->response(
            (string) $cibilReport->pdf_path,
            'cibil-report-'.$cibilReport->getKey().'-'.($cibilReport->fetched_at ?? $cibilReport->created_at)?->format('Y-m-d').'.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }
}
