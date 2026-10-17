<?php

namespace Tests\Feature;

use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Models\CibilReport;
use App\Models\CibilSetting;
use App\Models\User;
use App\Services\Cibil\CibilClient;
use App\Services\Cibil\CibilReportService;
use App\Services\Cibil\SurepassCibilClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The live Surepass driver, against faked HTTP only — no real (billable)
 * call is ever made: stray requests fail the test. Each case goes through
 * CibilReportService, so what is checked is what ends up on the request
 * row: a request only counts as fetched when the provider's answer checks
 * out; anything that may have been processed without a usable answer is
 * kept as "outcome uncertain"; nothing is retried; the token, PAN, report
 * and signed PDF link never reach the logs.
 */
class CibilSurepassClientTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'test-surepass-token-not-real';

    private const string BASE = 'https://kyc-api.surepass.app';

    private const string SCORE_URL = self::BASE.'/api/v1/credit-report-cibil/score';

    private const string REPORT_URL = self::BASE.'/api/v1/credit-report-cibil/fetch-report';

    private const string PDF_URL = self::BASE.'/api/v1/credit-report-cibil/fetch-report-pdf';

    private const string SIGNED_LINK = 'https://reports.example-bureau-storage.test/cibil/report.pdf?signature=abc123secret&expires=1700000000';

    private User $admin;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        config([
            'cibil.driver' => 'surepass',
            'cibil.surepass.token' => self::TOKEN,
            'cibil.surepass.base_url' => self::BASE,
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
    private function applicant(array $overrides = []): array
    {
        return [
            'customer_name' => 'Test Applicant',
            'pan_number' => 'ABCPD1234F',
            'mobile_no' => '9999000900',
            'gender' => 'male',
            'consent' => true,
            'consent_method' => 'recorded_call',
            ...$overrides,
        ];
    }

    private function send(CibilRequestType $type = CibilRequestType::Score): CibilReport
    {
        return app(CibilReportService::class)->request($this->admin, $type, $this->applicant())->report->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function success(array $data): array
    {
        return ['data' => $data, 'status_code' => 200, 'success' => true, 'message' => 'Success', 'message_code' => 'success'];
    }

    private function assertNothingSensitiveLogged(): void
    {
        $logs = implode("\n", $this->logged);
        $activity = Activity::query()->get()->map(fn (Activity $entry): string => $entry->description.json_encode($entry->properties))->implode("\n");

        foreach ([$logs, $activity] as $haystack) {
            $this->assertStringNotContainsString(self::TOKEN, $haystack);
            $this->assertStringNotContainsString('ABCPD1234F', $haystack);
            $this->assertStringNotContainsString('9999000900', $haystack);
            $this->assertStringNotContainsString('signature=', $haystack);
        }
    }

    // ---- Successful answers -------------------------------------------------

    public function test_a_score_request_sends_the_surepass_body_and_stores_the_score_and_client_id(): void
    {
        Http::fake([self::SCORE_URL => Http::response($this->success([
            'client_id' => 'cibil_credit_score_lGzdvkNakDDseIbcCMkr',
            'name' => 'TEST APPLICANT',
            'mobile' => '9999000900',
            'pan' => 'ABCPD1234F',
            'gender' => 'male',
            'credit_score' => '663',
        ]))]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame(CibilRequestType::Score, $report->request_type);
        $this->assertSame(663, $report->score);
        $this->assertSame('cibil_credit_score_lGzdvkNakDDseIbcCMkr', $report->bureau_reference);
        $this->assertSame(200, $report->provider_status_code);
        $this->assertSame('success', $report->provider_message_code);
        $this->assertNotNull($report->completed_at);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::SCORE_URL
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->data() === ['pan' => 'ABCPD1234F', 'mobile' => '9999000900', 'name' => 'Test Applicant', 'gender' => 'male', 'consent' => 'Y']);

        $this->assertNothingSensitiveLogged();
    }

    public function test_a_full_report_keeps_the_complete_credit_report(): void
    {
        Http::fake([self::REPORT_URL => Http::response($this->success([
            'client_id' => 'credit_report_cibil_ggXPwjqdgRSaInRLfxpZ',
            'credit_score' => '750',
            'user_email' => null,
            'credit_report' => [[
                'control_number' => '12345677890',
                'names' => [['name' => 'TEST APPLICANT']],
                'accounts' => [['memberShortName' => 'SAMPLE BANK', 'accountType' => '10']],
                'enquiries' => [],
                'response' => ['consumerSummaryresp' => ['accountSummary' => ['totalAccounts' => 1], 'inquirySummary' => []]],
                'unexpected_field' => 'kept as received',
            ]],
        ]))]);

        $report = $this->send(CibilRequestType::FullReport);

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame(750, $report->score);
        $this->assertSame('credit_report_cibil_ggXPwjqdgRSaInRLfxpZ', $report->bureau_reference);
        $this->assertSame('12345677890', $report->response_payload['credit_report'][0]['control_number']);
        $this->assertSame('SAMPLE BANK', $report->response_payload['credit_report'][0]['accounts'][0]['memberShortName']);
        $this->assertSame('kept as received', $report->response_payload['credit_report'][0]['unexpected_field']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::REPORT_URL);
        $this->assertNothingSensitiveLogged();
    }

    public function test_several_report_objects_are_all_kept(): void
    {
        Http::fake([self::REPORT_URL => Http::response($this->success([
            'client_id' => 'credit_report_cibil_multi',
            'credit_score' => '712',
            'credit_report' => [['control_number' => 'A1'], ['control_number' => 'B2']],
        ]))]);

        $report = $this->send(CibilRequestType::FullReport);

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame(['A1', 'B2'], array_column($report->response_payload['credit_report'], 'control_number'));
    }

    public function test_a_pdf_request_saves_the_linked_pdf_privately_and_forgets_the_signed_link(): void
    {
        Http::fake([
            self::PDF_URL => Http::response($this->success([
                'client_id' => 'credit_report_cibil_pdf_xfOSfdDRgierjgNdZelb',
                'credit_score' => '750',
                'credit_report' => null,
                'credit_report_link' => self::SIGNED_LINK,
                'credit_report_base64' => null,
            ])),
            'reports.example-bureau-storage.test/*' => Http::response("%PDF-1.4\nfake pdf body\n%%EOF", 200, ['Content-Type' => 'application/pdf']),
        ]);

        $report = $this->send(CibilRequestType::Pdf);

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame(750, $report->score);
        $this->assertSame(CibilPdfStatus::Available, $report->pdf_status);
        $this->assertNull($report->pdf_link);
        Storage::disk('local')->assertExists($report->pdf_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($report->pdf_path));

        // Neither the link nor the file is kept in the stored answer.
        $this->assertArrayNotHasKey('credit_report_link', $report->response_payload);
        $this->assertArrayNotHasKey('credit_report_base64', $report->response_payload);
        $this->assertStringNotContainsString('signature=', json_encode($report->getAttributes()));

        $this->assertNothingSensitiveLogged();
    }

    public function test_a_pdf_sent_inline_as_base64_is_saved_without_a_download(): void
    {
        Http::fake([self::PDF_URL => Http::response($this->success([
            'client_id' => 'credit_report_cibil_pdf_inline',
            'credit_score' => '701',
            'credit_report_link' => null,
            'credit_report_base64' => base64_encode("%PDF-1.4\ninline\n%%EOF"),
        ]))]);

        $report = $this->send(CibilRequestType::Pdf);

        $this->assertSame(CibilPdfStatus::Available, $report->pdf_status);
        Storage::disk('local')->assertExists($report->pdf_path);
        Http::assertSentCount(1);
    }

    // ---- Configuration -----------------------------------------------------

    public function test_a_missing_token_fails_before_anything_is_sent(): void
    {
        config(['cibil.surepass.token' => '']);
        Http::fake();

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Failed, $report->status);
        $this->assertSame('CIBIL credentials are not configured. Please contact the Admin.', $report->error_message);
        Http::assertNothingSent();
    }

    public function test_a_plain_http_base_url_is_refused_before_anything_is_sent(): void
    {
        config(['cibil.surepass.base_url' => 'http://kyc-api.surepass.app']);
        Http::fake();

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Failed, $report->status);
        Http::assertNothingSent();
    }

    public function test_the_surepass_driver_is_bound_only_when_configured(): void
    {
        $this->assertInstanceOf(SurepassCibilClient::class, app(CibilClient::class));
    }

    // ---- Provider errors ---------------------------------------------------

    public function test_an_invalid_token_401_fails_with_a_credentials_message(): void
    {
        Http::fake([self::SCORE_URL => Http::response(['success' => false, 'message' => 'Invalid token', 'status_code' => 401], 401)]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Failed, $report->status);
        $this->assertSame(401, $report->provider_status_code);
        $this->assertStringContainsString('did not accept the CIBIL credentials', $report->error_message);
        Http::assertSentCount(1);
    }

    public function test_a_400_is_a_rejection_with_the_providers_message_scrubbed(): void
    {
        Http::fake([self::SCORE_URL => Http::response([
            'data' => null,
            'success' => false,
            'status_code' => 400,
            'message' => 'Invalid details for PAN ABCPD1234F and mobile 9999000900',
            'message_code' => 'invalid_details',
        ], 400)]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Failed, $report->status);
        $this->assertSame(400, $report->provider_status_code);
        $this->assertSame('invalid_details', $report->provider_message_code);
        $this->assertStringStartsWith('The provider rejected the request.', $report->error_message);
        $this->assertStringContainsString('[PAN]', $report->error_message);
        $this->assertStringNotContainsString('ABCPD1234F', $report->error_message);
        $this->assertStringNotContainsString('9999000900', $report->error_message);
        Http::assertSentCount(1);
    }

    public function test_a_403_means_the_account_is_not_permitted(): void
    {
        Http::fake([self::SCORE_URL => Http::response(['success' => false, 'message' => 'Forbidden'], 403)]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Failed, $report->status);
        $this->assertStringContainsString('not permitted', $report->error_message);
    }

    public function test_a_429_asks_to_wait_and_is_not_retried(): void
    {
        Http::fake([self::SCORE_URL => Http::response(['success' => false, 'message' => 'Too many requests'], 429)]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Failed, $report->status);
        $this->assertStringContainsString('limiting requests', $report->error_message);
        Http::assertSentCount(1);
    }

    public function test_a_500_is_an_uncertain_outcome_and_a_503_is_unavailable(): void
    {
        Http::fake([self::SCORE_URL => Http::sequence()
            ->push(['success' => false, 'message' => 'Internal server error'], 500)
            ->push(['success' => false, 'message' => 'Service unavailable'], 503)]);

        $first = $this->send();

        $this->assertSame(CibilReportStatus::Uncertain, $first->status);
        $this->assertSame(500, $first->provider_status_code);
        $this->assertStringContainsString('Verify its status before retrying', $first->error_message);

        // An uncertain request blocks the next one until it is verified.
        app(CibilReportService::class)->resolveUncertain($this->admin, $first, 'Checked the Surepass dashboard: not billed.');

        $second = $this->send();

        $this->assertSame(CibilReportStatus::Failed, $second->status);
        $this->assertSame('The CIBIL service is temporarily unavailable. Please try again later.', $second->error_message);
        Http::assertSentCount(2);
    }

    public function test_a_timeout_after_sending_is_an_uncertain_outcome_and_is_not_retried(): void
    {
        Http::fake([self::SCORE_URL => Http::failedConnection('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received')]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Uncertain, $report->status);
        $this->assertNull($report->provider_status_code);
        Http::assertSentCount(1);
        $this->assertNothingSensitiveLogged();
    }

    public function test_a_connection_that_never_opened_is_a_plain_failure(): void
    {
        Http::fake([self::SCORE_URL => Http::failedConnection('cURL error 7: Failed to connect to kyc-api.surepass.app port 443')]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Failed, $report->status);
        $this->assertSame('The CIBIL service is temporarily unavailable. Please try again later.', $report->error_message);
    }

    // ---- Malformed and incomplete answers ----------------------------------

    public function test_malformed_json_on_a_200_is_an_uncertain_outcome(): void
    {
        Http::fake([self::SCORE_URL => Http::response('<html>gateway page</html>', 200)]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Uncertain, $report->status);
        $this->assertNull($report->score);
    }

    public function test_a_success_without_data_is_an_uncertain_outcome_and_success_false_a_rejection(): void
    {
        Http::fake([self::SCORE_URL => Http::sequence()
            ->push(['status_code' => 200, 'success' => true, 'message' => 'Success'], 200)
            ->push(['data' => null, 'status_code' => 200, 'success' => false, 'message' => 'Record not found', 'message_code' => 'not_found'], 200)]);

        $first = $this->send();
        $this->assertSame(CibilReportStatus::Uncertain, $first->status);

        app(CibilReportService::class)->resolveUncertain($this->admin, $first, 'Verified with provider.');

        $second = $this->send();
        $this->assertSame(CibilReportStatus::Failed, $second->status);
        $this->assertSame('The provider rejected the request. Record not found', $second->error_message);
        $this->assertSame('not_found', $second->provider_message_code);
    }

    public function test_a_missing_score_is_kept_as_fetched_without_a_score(): void
    {
        Http::fake([self::SCORE_URL => Http::response($this->success(['client_id' => 'cibil_credit_score_noscore', 'credit_score' => null]))]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertNull($report->score);
        $this->assertSame('cibil_credit_score_noscore', $report->bureau_reference);
    }

    public function test_a_no_history_score_and_an_impossible_score_are_read_safely(): void
    {
        Http::fake([self::SCORE_URL => Http::sequence()
            ->push($this->success(['client_id' => 'a', 'credit_score' => '-1']))
            ->push($this->success(['client_id' => 'b', 'credit_score' => '9999']))]);

        $this->assertSame(-1, $this->send()->score);
        $this->assertNull(app(CibilReportService::class)->request($this->admin, CibilRequestType::Score, $this->applicant(['pan_number' => 'ZZZPD1234F']))->report->score);
    }

    public function test_a_missing_client_id_keeps_the_answer_without_a_provider_reference(): void
    {
        Http::fake([self::SCORE_URL => Http::response($this->success(['credit_score' => '688']))]);

        $report = $this->send();

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame(688, $report->score);
        $this->assertNull($report->bureau_reference);
    }

    public function test_an_empty_or_missing_credit_report_is_a_failure_not_a_reusable_report(): void
    {
        Http::fake([self::REPORT_URL => Http::sequence()
            ->push($this->success(['client_id' => 'x', 'credit_score' => '700', 'credit_report' => []]))
            ->push($this->success(['client_id' => 'y', 'credit_score' => '700']))]);

        $empty = $this->send(CibilRequestType::FullReport);
        $missing = $this->send(CibilRequestType::FullReport);

        foreach ([$empty, $missing] as $report) {
            $this->assertSame(CibilReportStatus::Failed, $report->status);
            $this->assertSame('The provider returned no credit report for this applicant.', $report->error_message);
        }
    }

    public function test_a_pdf_answer_without_link_or_file_is_a_failure(): void
    {
        Http::fake([self::PDF_URL => Http::response($this->success([
            'client_id' => 'credit_report_cibil_pdf_none',
            'credit_score' => '750',
            'credit_report_link' => null,
            'credit_report_base64' => null,
        ]))]);

        $report = $this->send(CibilRequestType::Pdf);

        $this->assertSame(CibilReportStatus::Failed, $report->status);
        $this->assertSame('The provider did not return a PDF report.', $report->error_message);
        $this->assertNull($report->pdf_path);
    }

    public function test_a_failed_request_never_overwrites_an_earlier_fetched_report(): void
    {
        Http::fake([self::SCORE_URL => Http::sequence()
            ->push($this->success(['client_id' => 'first', 'credit_score' => '741']))
            ->push(['success' => false, 'message' => 'Bad request'], 400)]);

        $first = $this->send();
        $second = $this->send();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(CibilReportStatus::Success, $first->fresh()->status);
        $this->assertSame(741, $first->fresh()->score);
        $this->assertSame(CibilReportStatus::Failed, $second->status);
    }
}
