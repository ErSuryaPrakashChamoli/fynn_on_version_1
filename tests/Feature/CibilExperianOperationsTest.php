<?php

namespace Tests\Feature;

use App\Enums\CibilFailureCategory;
use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Filament\Resources\CibilReports\Pages\ViewCibilReport;
use App\Filament\Resources\CibilRequests\Pages\ListCibilRequests;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\CibilReport;
use App\Models\CibilSetting;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Cibil\CibilClient;
use App\Services\Cibil\CibilReportService;
use App\Services\Cibil\CibilRequestRefused;
use App\Support\CibilAccess;
use App\Support\ExperianReport;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Experian through Surepass, end to end with Http::fake (no real call is
 * ever made): every provider answer is stored with its failure type,
 * duration and correlation id; full reports are kept whole; PDFs are only
 * fetched from Surepass's S3 bucket, validated and checksummed; refusals,
 * denials and incomplete requests are audited without personal data; and
 * the report page lays the Experian report out section by section.
 */
class CibilExperianOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'experian-token-not-real';

    private const string BASE = 'https://kyc-api.surepass.app/api/v1/credit-report-experian';

    private const string S3_LINK = 'https://aadhaar-kyc-docs.s3.amazonaws.com/credit_report_experian/report.pdf?X-Amz-Signature=abc123secret';

    private const string PAN = 'ABCPD1234F';

    private const string MOBILE = '9999000900';

    private User $admin;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Caller', 'guard_name' => 'web']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

        $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);

        config([
            'cibil.driver' => 'surepass',
            'cibil.experian.token' => self::TOKEN,
            'cibil.experian.base_url' => 'https://kyc-api.surepass.app',
        ]);
        $this->app->forgetInstance(CibilClient::class);

        CibilSetting::current()->update(['requests_enabled' => true]);
        Storage::fake('local');
        Http::preventStrayRequests();

        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return [
            'customer_name' => 'Rajiv Talwar',
            'pan_number' => self::PAN,
            'mobile_no' => self::MOBILE,
            'consent' => true,
            'consent_method' => 'recorded_call',
            ...$overrides,
        ];
    }

    private function send(CibilRequestType $type = CibilRequestType::Score, ?User $user = null, ?Customer $customer = null): CibilReport
    {
        return app(CibilReportService::class)
            ->request($user ?? $this->admin, $type, $this->details(), $customer, bureau: CreditBureau::Experian)
            ->report
            ->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function success(array $data): array
    {
        return ['data' => $data, 'status_code' => 200, 'success' => true, 'message' => 'Success', 'message_code' => 'success'];
    }

    private function assertNothingSensitiveLogged(): void
    {
        $activity = Activity::query()->get()->map(fn (Activity $entry): string => $entry->description.json_encode($entry->properties))->implode("\n");

        foreach ([implode("\n", $this->logged), $activity] as $haystack) {
            $this->assertStringNotContainsString(self::TOKEN, $haystack);
            $this->assertStringNotContainsString(self::PAN, $haystack);
            $this->assertStringNotContainsString(self::MOBILE, $haystack);
            $this->assertStringNotContainsString('abc123secret', $haystack);
        }
    }

    // ---- Score API: every provider answer ------------------------------------

    public function test_a_score_is_stored_with_its_reference_duration_and_request_id(): void
    {
        Http::fake([self::BASE.'/score' => Http::response(self::success([
            'client_id' => 'experian_credit_score_BLffggUeWtqOpHTjugpw',
            'name' => 'RAJIV TALWAR', 'mobile' => self::MOBILE, 'pan' => self::PAN,
            'credit_score' => '796',
        ]))]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame(796, $report->score);
        $this->assertSame('experian_credit_score_BLffggUeWtqOpHTjugpw', $report->bureau_reference);
        $this->assertSame(200, $report->provider_status_code);
        $this->assertNull($report->failure_category);
        $this->assertNotNull($report->duration_ms);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $report->uuid);
        $this->assertSame('Answered', $report->providerContact());

        $log = Activity::query()->where('event', 'created')->sole();
        $this->assertSame($report->uuid, $log->getProperty('request_uuid'));
        $this->assertSame('experian', $log->getProperty('bureau'));
        $this->assertNothingSensitiveLogged();
    }

    /**
     * @return array<string, array{0: int, 1: array<string, mixed>|string, 2: string, 3: string}>
     */
    public static function providerAnswers(): array
    {
        $error = fn (string $message, string $code): array => ['success' => false, 'message' => $message, 'message_code' => $code];

        return [
            'HTTP 400' => [400, $error('Invalid PAN '.self::PAN, 'invalid_pan'), 'failed', 'provider_rejected'],
            'HTTP 401' => [401, $error('Unauthorized', 'unauthorized'), 'failed', 'authentication'],
            'HTTP 403' => [403, $error('Forbidden', 'forbidden'), 'failed', 'authorization'],
            'HTTP 404' => [404, $error('Not found', 'not_found'), 'failed', 'provider_rejected'],
            'HTTP 408' => [408, $error('Request timeout', 'timeout'), 'failed', 'provider_rejected'],
            'HTTP 422' => [422, $error('Mobile '.self::MOBILE.' does not match', 'invalid'), 'failed', 'provider_rejected'],
            'HTTP 429' => [429, $error('Too many requests', 'rate_limited'), 'failed', 'rate_limit'],
            'HTTP 500' => [500, 'Internal Server Error', 'uncertain', 'provider_error'],
            'HTTP 502' => [502, 'Bad Gateway', 'uncertain', 'provider_error'],
            'HTTP 503' => [503, 'Service Unavailable', 'failed', 'provider_error'],
            'HTTP 504' => [504, 'Gateway Timeout', 'uncertain', 'provider_error'],
            '200 with success false' => [200, $error('No record found', 'not_found'), 'failed', 'provider_rejected'],
            '200 with invalid JSON' => [200, '<html><body>Gateway</body></html>', 'uncertain', 'malformed_response'],
            '200 without data' => [200, ['success' => true, 'status_code' => 200], 'uncertain', 'malformed_response'],
        ];
    }

    /**
     * @param  array<string, mixed>|string  $body
     */
    #[DataProvider('providerAnswers')]
    public function test_each_provider_answer_is_kept_with_its_failure_type(int $status, array|string $body, string $expectedStatus, string $expectedCategory): void
    {
        Http::fake([self::BASE.'/score' => Http::response($body, $status)]);

        $report = $this->send();

        $this->assertSame($expectedStatus, $report->status->value);
        $this->assertSame($expectedCategory, $report->failure_category?->value);
        $this->assertNull($report->score);
        $this->assertNotNull($report->duration_ms);
        $this->assertNotNull($report->error_message);
        $this->assertStringNotContainsString(self::PAN, (string) $report->error_message);
        $this->assertStringNotContainsString(self::MOBILE, (string) $report->error_message);
        Http::assertSentCount(1);
        $this->assertNothingSensitiveLogged();
    }

    public function test_a_timeout_after_sending_is_uncertain_and_a_connection_never_opened_is_a_network_failure(): void
    {
        Http::fake([self::BASE.'/score' => Http::failedConnection('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received')]);
        $timedOut = $this->send();

        $this->assertSame(CibilReportStatus::Uncertain, $timedOut->status);
        $this->assertSame(CibilFailureCategory::Timeout, $timedOut->failure_category);
        $this->assertSame('Unknown', $timedOut->providerContact());

        // Never retried automatically: the uncertain request blocks the next one.
        try {
            $this->send();
            $this->fail('Expected the request to be refused.');
        } catch (CibilRequestRefused $exception) {
            $this->assertSame($timedOut->id, $exception->blockingReport?->id);
        }

        Http::fake([self::BASE.'/fetch-report' => Http::failedConnection('cURL error 7: Failed to connect to kyc-api.surepass.app port 443')]);
        $refused = $this->send(CibilRequestType::FullReport);

        $this->assertSame(CibilReportStatus::Failed, $refused->status);
        $this->assertSame(CibilFailureCategory::Network, $refused->failure_category);
        $this->assertSame('The Experian service is temporarily unavailable. Please try again later.', $refused->error_message);
        $this->assertSame('Not sent', $refused->providerContact());
    }

    public function test_a_missing_score_or_client_id_keeps_the_answer_without_inventing_one(): void
    {
        Http::fake([self::BASE.'/score' => Http::response(self::success(['name' => 'RAJIV TALWAR', 'pan' => self::PAN]))]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertNull($report->score);
        $this->assertNull($report->bureau_reference);
    }

    public function test_without_an_experian_token_nothing_is_sent(): void
    {
        config(['cibil.experian.token' => null, 'cibil.surepass.token' => 'cibil-token-not-real']);
        $this->app->forgetInstance(CibilClient::class);
        Http::fake();

        $report = $this->send();

        $this->assertSame(CibilFailureCategory::NotConfigured, $report->failure_category);
        $this->assertSame('Not sent', $report->providerContact());
        Http::assertNothingSent();
    }

    // ---- Full report ------------------------------------------------------------

    public function test_a_large_nested_report_is_kept_whole_and_encrypted(): void
    {
        $accounts = collect(range(1, 250))->map(fn (int $i): array => [
            'Subscriber_Name' => "Lender {$i}",
            'Account_Number' => 'XXXXXXXXXXX'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'Current_Balance' => $i * 1000,
            'CAIS_Account_History' => [['Year' => 2024, 'Month' => 1, 'Days_Past_Due' => 0]],
            'Written_off_Settled_Status' => null,
        ])->all();

        $report = [
            'CreditProfileHeader' => ['ReportDate' => 20240705, 'ReportTime' => 172347, 'Version' => 'V2.4', 'ReportNumber' => 1720180426926],
            'Current_Application' => [],
            'CAIS_Account' => ['CAIS_Summary' => ['Credit_Account' => ['CreditAccountTotal' => 250]], 'CAIS_Account_DETAILS' => $accounts],
            'SomeNewSection' => ['Added_By_Surepass' => true, 'Nested' => ['Deep' => ['Value' => 'kept']]],
        ];

        Http::fake([self::BASE.'/fetch-report' => Http::response(self::success([
            'client_id' => 'credit_report_experian_SivVmssPcdDwdnhnqghz',
            'credit_score' => '792',
            'credit_report' => $report,
        ]))]);

        $stored = $this->send(CibilRequestType::FullReport);

        $this->assertSame(CibilReportStatus::Success, $stored->status);
        $this->assertSame(792, $stored->score);
        $this->assertSame([$report], $stored->response_payload['credit_report']);
        $this->assertCount(250, $stored->response_payload['credit_report'][0]['CAIS_Account']['CAIS_Account_DETAILS']);

        // Encrypted at rest: the raw column holds none of it.
        $raw = (string) CibilReport::query()->toBase()->where('id', $stored->id)->value('response_payload');
        $this->assertStringNotContainsString('Lender 250', $raw);
    }

    public function test_an_empty_or_missing_report_is_a_failure_not_a_report(): void
    {
        Http::fakeSequence(self::BASE.'/fetch-report')
            ->push(self::success(['client_id' => 'a', 'credit_score' => '700', 'credit_report' => []]))
            ->push(self::success(['client_id' => 'b', 'credit_score' => '700']));

        $empty = $this->send(CibilRequestType::FullReport);
        $missing = $this->send(CibilRequestType::FullReport);

        foreach ([$empty, $missing] as $report) {
            $this->assertSame(CibilReportStatus::Failed, $report->status);
            $this->assertSame(CibilFailureCategory::MissingData, $report->failure_category);
            $this->assertNull($report->response_payload);
        }
    }

    // ---- PDF -----------------------------------------------------------------

    private function fakePdfAnswer(?string $link = self::S3_LINK, ?string $base64 = null): void
    {
        Http::fake([
            self::BASE.'/fetch-report-pdf' => Http::response(self::success(array_filter([
                'client_id' => 'credit_report_experian_pdf_yzWulawpzCczmLyKazqJ',
                'credit_score' => '761',
                'credit_report' => [],
                'credit_report_link' => $link,
                'credit_report_base64' => $base64,
            ], fn (mixed $value): bool => $value !== null))),
        ]);
    }

    public function test_the_pdf_is_fetched_from_the_s3_bucket_and_stored_privately_with_its_checksum(): void
    {
        $pdf = "%PDF-1.4\nexperian\n%%EOF\n";
        $this->fakePdfAnswer();
        Http::fake(['aadhaar-kyc-docs.s3.amazonaws.com/*' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])]);

        $report = $this->send(CibilRequestType::Pdf);

        $this->assertSame(CibilPdfStatus::Available, $report->pdf_status);
        Storage::disk('local')->assertExists($report->pdf_path);
        $this->assertSame(hash('sha256', $pdf), $report->pdf_checksum);
        $this->assertSame(strlen($pdf), $report->pdf_size_bytes);
        $this->assertNotNull($report->pdf_fetched_at);
        $this->assertNull($report->pdf_link);
        $this->assertArrayNotHasKey('credit_report_link', $report->response_payload);
        $this->assertNothingSensitiveLogged();
    }

    public function test_an_inline_base64_pdf_is_saved_without_a_download(): void
    {
        $this->fakePdfAnswer(link: null, base64: base64_encode("%PDF-1.7\ninline\n%%EOF"));

        $report = $this->send(CibilRequestType::Pdf);

        $this->assertSame(CibilPdfStatus::Available, $report->pdf_status);
        Http::assertSentCount(1);
    }

    public function test_a_link_to_another_host_is_never_fetched(): void
    {
        $this->fakePdfAnswer('https://attacker.example.com/internal/report.pdf');
        Http::fake(['attacker.example.com/*' => Http::response('%PDF-1.4 x', 200)]);

        $report = $this->send(CibilRequestType::Pdf);

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame(CibilPdfStatus::Blocked, $report->pdf_status);
        $this->assertNull($report->pdf_path);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'attacker.example.com'));
    }

    public function test_a_redirect_from_the_bucket_is_not_followed(): void
    {
        $this->fakePdfAnswer();
        Http::fake([
            'aadhaar-kyc-docs.s3.amazonaws.com/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data']),
            '169.254.169.254/*' => Http::response('%PDF-1.4 x', 200),
        ]);

        $report = $this->send(CibilRequestType::Pdf);

        $this->assertSame(CibilPdfStatus::Blocked, $report->pdf_status);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_html_an_invalid_file_and_an_oversized_file_are_not_stored(): void
    {
        config(['cibil.pdf.max_bytes' => 1024]);

        $cases = [
            ['<html>Access Denied</html>', ['Content-Type' => 'text/html']],
            ['not a pdf at all', ['Content-Type' => 'application/pdf']],
            ['%PDF-1.4 small', ['Content-Type' => 'application/pdf', 'Content-Length' => '99999999']],
        ];

        foreach ($cases as [$body, $headers]) {
            CibilReport::query()->delete();
            $this->fakePdfAnswer();
            Http::fake(['aadhaar-kyc-docs.s3.amazonaws.com/*' => Http::response($body, 200, $headers)]);

            $report = $this->send(CibilRequestType::Pdf);

            $this->assertSame(CibilPdfStatus::Invalid, $report->pdf_status);
            $this->assertNull($report->pdf_path);
        }

        Storage::disk('local')->assertDirectoryEmpty('cibil-reports');
    }

    public function test_an_expired_link_and_a_download_timeout_are_told_apart(): void
    {
        $this->fakePdfAnswer();
        Http::fake(['aadhaar-kyc-docs.s3.amazonaws.com/*' => Http::response('<Error>Request has expired</Error>', 403)]);
        $expired = $this->send(CibilRequestType::Pdf);

        $this->assertSame(CibilPdfStatus::Expired, $expired->pdf_status);
        $this->assertNull($expired->pdf_link);

        CibilReport::query()->delete();
        Http::fake(['aadhaar-kyc-docs.s3.amazonaws.com/*' => Http::failedConnection('cURL error 28: Operation timed out')]);
        $pending = $this->send(CibilRequestType::Pdf);

        // Kept (encrypted) so "Retry PDF download" can try once more without a new request.
        $this->assertSame(CibilPdfStatus::DownloadFailed, $pending->pdf_status);
        $this->assertSame(self::S3_LINK, $pending->pdf_link);
    }

    public function test_the_experian_pdf_route_checks_permission_and_visibility(): void
    {
        $this->fakePdfAnswer();
        Http::fake(['aadhaar-kyc-docs.s3.amazonaws.com/*' => Http::response("%PDF-1.4\nok\n%%EOF", 200)]);
        $report = $this->send(CibilRequestType::Pdf);

        $this->get($report->pdfUrl())->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $caller = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $caller->assignRole('Caller');
        CibilAccess::sync(['Caller'], []);

        // Has module access but cannot see the report: not found, not forbidden.
        $this->actingAs($caller->fresh())->get($report->pdfUrl())->assertNotFound();
    }

    // ---- Audit of what was not sent ---------------------------------------------

    public function test_refusals_denials_and_incomplete_requests_are_audited_without_personal_data(): void
    {
        Http::fake();

        // Experian switched off.
        CibilSetting::current()->update(['experian_enabled' => false]);
        try {
            $this->send();
        } catch (CibilRequestRefused) {
        }
        CibilSetting::current()->update(['experian_enabled' => true]);

        // Consent missing.
        try {
            app(CibilReportService::class)->request($this->admin, CibilRequestType::Score, $this->details(['consent' => false]), bureau: CreditBureau::Experian);
        } catch (ValidationException) {
        }

        // No permission for full reports.
        $caller = User::factory()->create();
        $caller->assignRole('Caller');
        CibilAccess::sync(['Caller'], []);
        try {
            $this->send(CibilRequestType::FullReport, $caller->fresh());
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, CibilReport::query()->count());
        Http::assertNothingSent();

        $refused = Activity::query()->where('event', 'refused')->sole();
        $this->assertStringContainsString('Experian requests are switched off', $refused->description);
        $this->assertSame('experian', $refused->getProperty('bureau'));

        $invalid = Activity::query()->where('event', 'invalid')->sole();
        $this->assertContains('consent', $invalid->getProperty('fields'));

        $denied = Activity::query()->where('event', 'denied')->sole();
        $this->assertSame($caller->id, (int) $denied->causer_id);

        $this->assertNothingSensitiveLogged();
    }

    public function test_a_duplicate_request_is_refused_and_logged_against_the_one_in_flight(): void
    {
        $pending = CibilReport::factory()->experian()->pending()->create(['pan_number' => self::PAN, 'request_type' => CibilRequestType::Score]);
        Http::fake();

        try {
            $this->send();
            $this->fail('Expected the request to be refused.');
        } catch (CibilRequestRefused $exception) {
            $this->assertStringContainsString('still processing', $exception->getMessage());
        }

        $log = Activity::query()->where('event', 'refused')->sole();
        $this->assertSame($pending->id, (int) $log->subject_id);
        Http::assertNothingSent();
    }

    // ---- Report viewer ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function sampleReport(): array
    {
        return [
            'CreditProfileHeader' => ['ReportDate' => 20240705, 'ReportTime' => 172347, 'Version' => 'V2.4', 'ReportNumber' => 1720180426926],
            'Current_Application' => ['Current_Application_Details' => [
                'Current_Applicant_Details' => [
                    'Last_Name' => 'SINGH', 'First_Name' => 'Munna', 'Middle_Name1' => '',
                    'IncomeTaxPan' => 'FPVPR1234Q', 'Date_Of_Birth_Applicant' => 19971008, 'MobilePhoneNumber' => 8890858585, 'EMailId' => '',
                ],
                'Current_Applicant_Address_Details' => ['FlatNoPlotNoHouseNo' => 'PLOT NO A 403', 'City' => 'Jaipur', 'PINCode' => 302033],
            ]],
            'CAIS_Account' => [
                'CAIS_Summary' => [
                    'Credit_Account' => ['CreditAccountTotal' => 44, 'CreditAccountActive' => 15, 'CreditAccountDefault' => 0, 'CreditAccountClosed' => 29],
                    'Total_Outstanding_Balance' => ['Outstanding_Balance_UnSecured' => 112480, 'Outstanding_Balance_All' => 122679],
                ],
                'CAIS_Account_DETAILS' => [[
                    'Subscriber_Name' => '<b>Sample Bank</b>', 'Account_Number' => '123456787938', 'Account_Type' => 13,
                    'Open_Date' => 20201017, 'Highest_Credit_or_Original_Loan_Amount' => 114000, 'Current_Balance' => 0,
                    'Amount_Past_Due' => 0, 'Account_Status' => 11, 'Date_Reported' => 20231130,
                ]],
            ],
            'Match_result' => ['Exact_match' => 'Y'],
        ];
    }

    public function test_the_reader_formats_dates_masks_identifiers_and_keeps_unknown_sections(): void
    {
        $report = ExperianReport::fromPayload(['credit_report' => [self::sampleReport()]]);

        $this->assertNotNull($report);
        $this->assertSame(['report_date' => '05 Jul 2024', 'report_time' => '17:23:47', 'report_number' => '1720180426926', 'version' => 'V2.4'], $report->header());

        $applicant = $report->applicant();
        $this->assertSame('Munna SINGH', $applicant['Name']);
        $this->assertSame('FPXXXXXX4Q', $applicant['PAN']);
        $this->assertSame('08 Oct 1997', $applicant['Date of birth']);
        $this->assertSame('XXXXXX8585', $applicant['Mobile']);
        $this->assertNull($applicant['Email']);
        $this->assertSame('PLOT NO A 403, Jaipur, 302033', $applicant['Address']);

        $this->assertSame('44', $report->creditSummary()['Total accounts']);
        $this->assertNull($report->creditSummary()['Outstanding (secured)']);

        $account = $report->accounts()[0];
        $this->assertSame('XXXXXXXX7938', $account['Account number']);
        $this->assertSame('17 Oct 2020', $account['Opened']);
        $this->assertSame('₹1,14,000', $account['Sanctioned / limit']);
        $this->assertNull($account['Closed']);

        $this->assertSame([], $report->enquiries());
        $this->assertSame(['Match_result' => ['Exact_match' => 'Y']], $report->otherSections());

        $this->assertNull(ExperianReport::fromPayload(['credit_report' => []]));
        $this->assertNull(ExperianReport::fromPayload(null));
    }

    public function test_the_report_page_lays_out_the_experian_report_and_escapes_its_content(): void
    {
        $report = CibilReport::factory()->experian()->create([
            'requested_by' => $this->admin->id,
            'score' => 792,
            'response_payload' => ['client_id' => 'x', 'credit_report' => [self::sampleReport()]],
        ]);

        Livewire::test(ViewCibilReport::class, ['record' => $report->getRouteKey()])
            ->assertSee('Experian score')
            ->assertSee('Credit accounts (1)')
            ->assertSee('XXXXXXXX7938')
            ->assertSee('Not available in this report')
            ->assertSee('Other report sections (1)')
            ->assertSee('<b>Sample Bank</b>')
            ->assertDontSee('<b>Sample Bank</b>', false)
            ->assertSee('Request ID')
            ->assertSee($report->uuid)
            ->assertSee('Audit trail');
    }

    public function test_the_full_report_needs_the_full_report_permission(): void
    {
        $caller = User::factory()->create();
        $caller->assignRole('Caller');
        CibilAccess::sync(['Caller'], []);
        $report = CibilReport::factory()->experian()->create([
            'requested_by' => $caller->id,
            'response_payload' => ['credit_report' => [self::sampleReport()]],
        ]);

        $this->actingAs($caller->fresh());

        Livewire::test(ViewCibilReport::class, ['record' => $report->getRouteKey()])
            ->assertOk()
            ->assertDontSee('Credit accounts')
            ->assertDontSee('Audit trail')
            ->assertDontSee('Request ID');
    }

    // ---- Requests listing ----------------------------------------------------

    public function test_the_requests_listing_filters_by_failure_type_and_by_customer_or_lead(): void
    {
        $customer = Customer::factory()->create();
        $timedOut = CibilReport::factory()->experian()->uncertain()->create(['failure_category' => CibilFailureCategory::Timeout, 'customer_id' => $customer->id]);
        $rejected = CibilReport::factory()->experian()->failed()->create(['failure_category' => CibilFailureCategory::ProviderRejected]);
        $ok = CibilReport::factory()->experian()->create(['customer_id' => $customer->id]);

        Livewire::test(ListCibilRequests::class)
            ->filterTable('failure_category', CibilFailureCategory::Timeout->value)
            ->assertCanSeeTableRecords([$timedOut])
            ->assertCanNotSeeTableRecords([$rejected, $ok])
            ->resetTableFilters()
            ->filterTable('fetched_for', 'customer')
            ->assertCanSeeTableRecords([$timedOut, $ok])
            ->assertCanNotSeeTableRecords([$rejected])
            ->resetTableFilters()
            ->searchTable($ok->uuid)
            ->assertCanSeeTableRecords([$ok])
            ->assertCanNotSeeTableRecords([$timedOut, $rejected]);
    }
}
