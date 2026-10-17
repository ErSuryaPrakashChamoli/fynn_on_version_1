<?php

namespace Tests\Feature;

use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Models\CibilReport;
use App\Models\CibilSetting;
use App\Models\Employee;
use App\Models\User;
use App\Services\Cibil\CibilClient;
use App\Services\Cibil\CibilPdfStore;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CIBIL PDFs: saved to private storage straight away, opened only through
 * the app's own route by someone who can see the report and holds the PDF
 * permission (every opening audited), never by the provider's signed link —
 * which is not logged, not shown, and not kept once the file is saved. An
 * expired link is recorded as such; an unreachable one can be retried
 * without a new bureau request.
 */
class CibilPdfTest extends TestCase
{
    use RefreshDatabase;

    private const string PDF_URL = 'https://kyc-api.surepass.app/api/v1/credit-report-cibil/fetch-report-pdf';

    private const string SIGNED_LINK = 'https://reports.example-bureau-storage.test/r/abc.pdf?signature=very-secret-sig&expires=1700000000';

    private User $admin;

    private User $caller;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Storage::fake('local');
        Storage::fake('public');
        CibilSetting::current()->update(['requests_enabled' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->caller = User::factory()->create(['employee_id' => $employee->id]);
        $this->caller->assignRole('Caller');
        CibilAccess::sync(['Caller'], []);
        $this->caller = $this->caller->fresh();

        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    private function savedPdfReport(array $attributes = []): CibilReport
    {
        $path = 'cibil-reports/1/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nsaved\n%%EOF");

        return CibilReport::factory()->pdf($path)->create(['requested_by' => $this->caller->id, ...$attributes]);
    }

    private function useSurepass(): void
    {
        config(['cibil.driver' => 'surepass', 'cibil.surepass.token' => 'test-token']);
        $this->app->forgetInstance(CibilClient::class);
        Http::preventStrayRequests();
    }

    private function requestPdf(): CibilReport
    {
        return app(CibilReportService::class)->request($this->admin, CibilRequestType::Pdf, [
            'customer_name' => 'Test Applicant',
            'pan_number' => 'ABCPD1234F',
            'mobile_no' => '9999000900',
            'gender' => 'male',
            'consent' => true,
            'consent_method' => 'recorded_call',
        ])->report->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function pdfAnswer(): array
    {
        return ['data' => ['client_id' => 'credit_report_cibil_pdf_x', 'credit_score' => '750', 'credit_report' => null, 'credit_report_link' => self::SIGNED_LINK, 'credit_report_base64' => null], 'status_code' => 200, 'success' => true, 'message_code' => 'success'];
    }

    // ---- Access -------------------------------------------------------------

    public function test_an_authorised_user_opens_the_pdf_through_the_app_and_it_is_audited(): void
    {
        CibilAccess::sync(['Caller'], [], CibilAccess::PDF_PERMISSION);
        $report = $this->savedPdfReport();

        $response = $this->actingAs($this->caller->fresh())->get($report->pdfUrl());

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());

        $this->assertTrue(Activity::query()
            ->where('event', 'pdf_downloaded')
            ->where('subject_id', $report->id)
            ->where('causer_id', $this->caller->id)
            ->exists());
    }

    public function test_the_pdf_needs_the_pdf_permission(): void
    {
        $report = $this->savedPdfReport();

        $this->actingAs($this->caller)->get($report->pdfUrl())->assertForbidden();
        $this->assertFalse(app(CibilReportService::class)->canDownloadPdf($report, $this->caller));
    }

    public function test_a_report_the_user_cannot_see_is_not_found_and_a_guest_must_log_in(): void
    {
        CibilAccess::sync(['Caller'], [], CibilAccess::PDF_PERMISSION);
        $someoneElses = $this->savedPdfReport(['requested_by' => $this->admin->id]);

        $this->actingAs($this->caller->fresh())->get($someoneElses->pdfUrl())->assertNotFound();

        // A guest never gets the file. (The app has no route named "login",
        // so the auth middleware's guest redirect errors on every plain
        // route — a pre-existing gap, not specific to CIBIL.)
        auth()->logout();
        $guest = $this->get(route('cibil-reports.pdf', ['cibilReport' => $someoneElses->id]));
        $this->assertNotSame(200, $guest->getStatusCode());
        $this->assertNotSame('application/pdf', $guest->headers->get('Content-Type'));
    }

    public function test_a_report_without_a_saved_pdf_has_no_pdf_url(): void
    {
        $report = CibilReport::factory()->pdf()->create(['requested_by' => $this->caller->id]);

        $this->assertNull($report->pdfUrl());
        $this->actingAs($this->admin)->get(route('cibil-reports.pdf', ['cibilReport' => $report->id]))->assertNotFound();
    }

    public function test_the_demo_environment_has_its_own_pdf_route(): void
    {
        $this->assertStringEndsWith('/demo/cibil-reports/5/pdf', route('demo.cibil-reports.pdf', ['cibilReport' => 5]));
    }

    // ---- Saving the provider's PDF -------------------------------------------

    public function test_an_expired_link_is_recorded_and_the_link_is_not_kept(): void
    {
        $this->useSurepass();
        Http::fake([
            self::PDF_URL => Http::response($this->pdfAnswer()),
            'reports.example-bureau-storage.test/*' => Http::response('<Error>Request has expired</Error>', 403),
        ]);

        $report = $this->requestPdf();

        $this->assertSame(CibilReportStatus::Success, $report->status, 'The billed call itself succeeded.');
        $this->assertSame(CibilPdfStatus::Expired, $report->pdf_status);
        $this->assertNull($report->pdf_link);
        $this->assertNull($report->pdfUrl());
        $this->assertFalse(app(CibilReportService::class)->canRetryPdfDownload($report));
        $this->assertStringNotContainsString('very-secret-sig', implode("\n", $this->logged));
    }

    public function test_an_unreachable_link_is_kept_encrypted_and_can_be_retried_without_a_new_request(): void
    {
        $this->useSurepass();
        Http::fake([
            self::PDF_URL => Http::response($this->pdfAnswer()),
            'reports.example-bureau-storage.test/*' => Http::sequence()
                ->pushFailedConnection('cURL error 28: Operation timed out')
                ->push("%PDF-1.4\nretried\n%%EOF", 200),
        ]);

        $report = $this->requestPdf();

        $this->assertSame(CibilPdfStatus::DownloadFailed, $report->pdf_status);
        $this->assertSame(self::SIGNED_LINK, $report->pdf_link);

        // Encrypted at rest, never in the logs, never serialised.
        $raw = (string) CibilReport::query()->toBase()->where('id', $report->id)->value('pdf_link');
        $this->assertStringNotContainsString('very-secret-sig', $raw);
        $this->assertArrayNotHasKey('pdf_link', $report->toArray());
        $this->assertStringNotContainsString('very-secret-sig', implode("\n", $this->logged));

        $retried = app(CibilReportService::class)->retryPdfDownload($this->admin, $report);

        $this->assertSame(CibilPdfStatus::Available, $retried->pdf_status);
        $this->assertNull($retried->fresh()->pdf_link);
        Storage::disk('local')->assertExists($retried->pdf_path);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->url() === self::PDF_URL);
    }

    public function test_content_that_is_not_a_pdf_or_is_too_large_is_not_saved(): void
    {
        $store = app(CibilPdfStore::class);
        $report = CibilReport::factory()->pdf()->create();

        $this->assertSame(CibilPdfStatus::Invalid, $store->store($report, base64_encode('<html>not a pdf</html>'), null)->status);
        $this->assertSame(CibilPdfStatus::Invalid, $store->store($report, 'not base64 at all!!', null)->status);
        $this->assertSame(CibilPdfStatus::Missing, $store->store($report, null, null)->status);
        $this->assertSame(CibilPdfStatus::Invalid, $store->download($report, 'http://insecure.example.test/report.pdf')->status);

        config(['cibil.pdf.max_bytes' => 10]);
        $this->assertSame(CibilPdfStatus::Invalid, $store->store($report, base64_encode("%PDF-1.4\nlonger than ten bytes"), null)->status);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pdfs_are_only_ever_written_to_a_private_disk(): void
    {
        config(['cibil.pdf.disk' => 'public']);
        $report = CibilReport::factory()->pdf()->create();

        $stored = app(CibilPdfStore::class)->store($report, base64_encode("%PDF-1.4\nprivate\n%%EOF"), null);

        $this->assertSame(CibilPdfStatus::Available, $stored->status);
        Storage::disk('local')->assertExists($stored->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
