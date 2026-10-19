<?php

namespace Tests\Feature;

use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Filament\Pages\CibilSettings;
use App\Filament\Resources\CibilReports\Pages\CreateCibilReport;
use App\Filament\Resources\CibilReports\Pages\ListCibilReports;
use App\Filament\Resources\CibilReports\Pages\ViewCibilReport;
use App\Filament\Resources\CibilReports\RelationManagers\CibilProfileRelationManager;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\CibilReport;
use App\Models\CibilSetting;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Services\Cibil\CibilApplicant;
use App\Services\Cibil\CibilClient;
use App\Services\Cibil\CibilFetchResult;
use App\Services\Cibil\CibilReportService;
use App\Services\Cibil\CibilRequestRefused;
use App\Services\Cibil\FakeCibilClient;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The user picks the bureau — CIBIL or Experian — before anything else on a
 * request. Both are always offered with every request type, whatever the
 * state of either bureau's API. Each bureau has its own endpoints and token,
 * and one bureau's report, pending request or uncertain outcome never counts
 * for the other.
 */
class CibilBureauChoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var object{calls: list<string>} */
    private object $bureau;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

        // Records which bureau every billable call went to.
        $this->bureau = new class implements CibilClient
        {
            /** @var list<string> */
            public array $calls = [];

            public function fetch(CibilApplicant $applicant, CibilRequestType $type, CreditBureau $bureau = CreditBureau::Cibil): CibilFetchResult
            {
                $this->calls[] = $bureau->value.':'.$type->value;

                return (new FakeCibilClient)->fetch($applicant, $type, $bureau);
            }
        };
        $this->app->instance(CibilClient::class, $this->bureau);

        CibilSetting::current()->update(['requests_enabled' => true]);

        $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);
    }

    private function customer(): Customer
    {
        $owner = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);

        return Customer::factory()->create([
            'assign_to' => $owner->id,
            'employee_id' => $owner->id,
            'customer_name' => 'Meena Iyer',
            'pan_number' => 'MEENA1234I',
            'mobile_no' => '9811122233',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return [
            'customer_name' => 'Meena Iyer',
            'pan_number' => 'MEENA1234I',
            'mobile_no' => '9811122233',
            'gender' => 'female',
            'consent' => true,
            'consent_method' => 'recorded_call',
            ...$overrides,
        ];
    }

    private function service(): CibilReportService
    {
        return app(CibilReportService::class);
    }

    // ---- The fetch form ---------------------------------------------------

    public function test_the_bureau_is_the_first_choice_on_the_fetch_form_and_defaults_to_cibil(): void
    {
        Livewire::test(CreateCibilReport::class)
            ->assertSee('Fetch bureau report')
            ->assertSeeInOrder(['Which credit bureau to ask', 'A report is fetched for a lead or for a customer file.', 'What to request'])
            ->assertSchemaStateSet(['bureau' => 'cibil'])
            ->assertFormFieldExists('bureau', fn (ToggleButtons $field): bool => $field->getOptions() === ['cibil' => 'CIBIL', 'experian' => 'Experian']
                && ! $field->isOptionDisabled('cibil', 'CIBIL') && ! $field->isOptionDisabled('experian', 'Experian'))
            ->assertDontSee('switched off');
    }

    public function test_both_bureaus_offer_every_request_type_even_before_their_api_is_set_up(): void
    {
        config(['cibil.experian.token' => null, 'cibil.experian.endpoints.score' => null]);

        foreach (['cibil', 'experian'] as $bureau) {
            Livewire::test(CreateCibilReport::class)
                ->fillForm(['bureau' => $bureau])
                ->assertFormFieldExists('bureau', fn (ToggleButtons $field): bool => ! $field->isOptionDisabled('cibil', 'CIBIL') && ! $field->isOptionDisabled('experian', 'Experian'))
                ->assertFormFieldExists('request_type', fn (ToggleButtons $field): bool => array_keys($field->getOptions()) === ['score', 'full_report', 'pdf']);
        }
    }

    public function test_an_experian_report_is_fetched_through_the_form_for_a_customer_file(): void
    {
        $customer = $this->customer();

        Livewire::test(CreateCibilReport::class)
            ->fillForm(['bureau' => 'experian'])
            ->fillForm(['fetch_for' => 'customer'])
            ->fillForm(['customer_id' => $customer->id])
            ->fillForm(['request_type' => 'full_report', 'gender' => 'female', 'consent_method' => 'recorded_call', 'consent' => true])
            ->assertSee('Experian credit report enquiry to assess loan eligibility')
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Experian report fetched');

        $report = CibilReport::query()->sole();

        $this->assertSame(CreditBureau::Experian, $report->bureau);
        $this->assertSame(CibilRequestType::FullReport, $report->request_type);
        $this->assertSame($customer->id, $report->customer_id);
        $this->assertSame('Experian credit report enquiry to assess loan eligibility', $report->consent_purpose);
        $this->assertSame(['experian:full_report'], $this->bureau->calls);
    }

    public function test_an_experian_score_check_is_sent_from_the_form(): void
    {
        $customer = $this->customer();

        Livewire::test(CreateCibilReport::class)
            ->fillForm(['bureau' => 'experian', 'fetch_for' => 'customer'])
            ->fillForm(['customer_id' => $customer->id])
            ->fillForm(['request_type' => 'score', 'gender' => 'female', 'consent_method' => 'recorded_call', 'consent' => true])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Experian report fetched');

        $this->assertSame(CreditBureau::Experian, CibilReport::query()->sole()->bureau);
        $this->assertSame(['experian:score'], $this->bureau->calls);
    }

    // ---- The service ------------------------------------------------------

    public function test_one_bureaus_report_never_stands_in_for_the_others(): void
    {
        $customer = $this->customer();

        $cibil = $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details(), $customer);
        $experian = $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details(), $customer, bureau: CreditBureau::Experian);
        $experianAgain = $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details(), $customer, bureau: CreditBureau::Experian);
        $cibilAgain = $this->service()->request($this->admin, CibilRequestType::Score, $this->details(), $customer);

        $this->assertFalse($experian->reused);
        $this->assertNotSame($cibil->report->id, $experian->report->id);
        $this->assertSame('No earlier Experian Full report on this file.', $experian->report->request_reason);

        // Each bureau reuses only its own report.
        $this->assertTrue($experianAgain->reused);
        $this->assertSame($experian->report->id, $experianAgain->report->id);
        $this->assertTrue($cibilAgain->reused);
        $this->assertSame($cibil->report->id, $cibilAgain->report->id);

        $this->assertSame(['cibil:full_report', 'experian:full_report'], $this->bureau->calls);
    }

    public function test_an_uncertain_request_blocks_only_its_own_bureau(): void
    {
        CibilReport::factory()->uncertain()->create(['pan_number' => 'MEENA1234I', 'request_type' => CibilRequestType::FullReport]);

        $experian = $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details(), bureau: CreditBureau::Experian);
        $this->assertSame(CibilReportStatus::Success, $experian->report->status);

        $this->expectException(CibilRequestRefused::class);
        $this->expectExceptionMessage('The request outcome is uncertain');

        $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details());
    }

    public function test_a_pending_request_blocks_only_its_own_bureau(): void
    {
        CibilReport::factory()->experian()->pending()->create(['pan_number' => 'MEENA1234I', 'request_type' => CibilRequestType::FullReport]);

        $cibil = $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details());
        $this->assertSame(CibilReportStatus::Success, $cibil->report->status);

        $this->expectException(CibilRequestRefused::class);
        $this->expectExceptionMessage('still processing');

        $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details(), bureau: CreditBureau::Experian);
    }

    // ---- Lead / customer profile and the report page ----------------------

    public function test_the_file_profile_asks_for_the_bureau_first(): void
    {
        $customer = $this->customer();

        $profile = fn () => Livewire::test(CibilProfileRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class]);
        $consent = ['gender' => 'female', 'consent_method' => 'recorded_call', 'consent' => true];

        $profile()
            ->assertActionExists(TestAction::make('checkCibilScore')->table(), fn ($action): bool => $action->getLabel() === 'Check credit score')
            ->callAction(TestAction::make('checkCibilScore')->table(), ['bureau' => 'experian', ...$consent])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $profile()
            ->callAction(TestAction::make('fetchCibilReport')->table(), ['bureau' => 'cibil', ...$consent])
            ->assertHasNoActionErrors();

        $reports = CibilReport::query()->orderBy('id')->get();

        $this->assertSame([CreditBureau::Experian, CreditBureau::Cibil], $reports->pluck('bureau')->all());
        $this->assertSame([$customer->id, $customer->id], $reports->pluck('customer_id')->all());
        $this->assertSame(['experian:score', 'cibil:full_report'], $this->bureau->calls);
    }

    public function test_refreshing_a_report_asks_the_same_bureau_again(): void
    {
        $customer = $this->customer();
        $old = CibilReport::factory()->experian()->create([
            'requested_by' => $this->admin->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Meena Iyer',
            'pan_number' => 'MEENA1234I',
            'mobile_no' => '9811122233',
            'gender' => 'female',
        ]);

        Livewire::test(ViewCibilReport::class, ['record' => $old->getRouteKey()])
            ->assertSee('Experian report — Meena Iyer')
            ->callAction(TestAction::make('fetchAgain'), [
                'request_type' => 'full_report',
                'reason' => 'Customer cleared a loan last week',
                'consent_method' => 'recorded_call',
                'consent' => true,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Experian report fetched');

        $new = CibilReport::query()->latest('id')->first();

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(CreditBureau::Experian, $new->bureau);
        $this->assertSame(['experian:full_report'], $this->bureau->calls);
    }

    public function test_the_report_page_and_listing_show_the_bureau(): void
    {
        $cibil = CibilReport::factory()->create(['pan_number' => 'AAAAA1111A', 'score' => 744]);
        $experian = CibilReport::factory()->experian()->create(['pan_number' => 'BBBBB2222B', 'score' => 781]);

        Livewire::test(ViewCibilReport::class, ['record' => $experian->getRouteKey()])
            ->assertSee('Experian score')
            ->assertSee('781');

        Livewire::test(ListCibilReports::class)
            ->assertCanSeeTableRecords([$cibil, $experian])
            ->assertTableColumnExists('bureau')
            ->filterTable('bureau', 'experian')
            ->assertCanSeeTableRecords([$experian])
            ->assertCanNotSeeTableRecords([$cibil]);
    }

    // ---- Settings and the live client --------------------------------------

    public function test_experian_has_its_own_switches_which_start_on_and_never_touch_cibil(): void
    {
        $settings = CibilSetting::current();

        $this->assertTrue($settings->allowsBureau(CreditBureau::Experian));
        $this->assertTrue($settings->allows(CibilRequestType::Score, CreditBureau::Experian));

        Livewire::test(CibilSettings::class)
            ->assertSchemaStateSet(['experian_enabled' => true, 'experian_score_enabled' => true])
            ->assertSeeInOrder(['CIBIL', 'Surepass token', 'Experian', 'Surepass token'])
            ->set('data.experian_enabled', false)
            ->set('data.pdf_enabled', false)
            ->call('save')
            ->assertNotified('CIBIL settings saved');

        $settings = CibilSetting::current();

        // Experian is off; CIBIL keeps working, except the CIBIL type switched off.
        $this->assertFalse($settings->allowsBureau(CreditBureau::Experian));
        $this->assertTrue($settings->allows(CibilRequestType::Score, CreditBureau::Cibil));
        $this->assertFalse($settings->allows(CibilRequestType::Pdf, CreditBureau::Cibil));

        // Turning a CIBIL type off does not reach Experian's own switch.
        $settings->update(['experian_enabled' => true]);
        $this->assertTrue($settings->fresh()->allows(CibilRequestType::Pdf, CreditBureau::Experian));
    }

    public function test_a_switched_off_experian_is_shown_as_unavailable_and_never_falls_back_to_cibil(): void
    {
        CibilSetting::current()->update(['experian_enabled' => false]);
        $customer = $this->customer();

        Livewire::test(CreateCibilReport::class)
            ->assertSee('Experian is switched off by the Admin')
            ->assertFormFieldExists('bureau', fn (ToggleButtons $field): bool => $field->isOptionDisabled('experian', 'Experian') && ! $field->isOptionDisabled('cibil', 'CIBIL'))
            ->fillForm(['bureau' => 'experian', 'fetch_for' => 'customer'])
            ->fillForm(['customer_id' => $customer->id])
            ->fillForm(['request_type' => 'full_report', 'consent_method' => 'recorded_call', 'consent' => true])
            ->call('create')
            ->assertHasFormErrors(['bureau']);

        try {
            $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details(), bureau: CreditBureau::Experian);
            $this->fail('Expected the request to be refused.');
        } catch (CibilRequestRefused $exception) {
            $this->assertStringContainsString('Experian requests are switched off', $exception->getMessage());
        }

        $this->assertSame(0, CibilReport::query()->count());
        $this->assertSame([], $this->bureau->calls);
    }

    public function test_experian_goes_to_its_own_endpoint_with_its_own_token_and_never_the_cibil_token(): void
    {
        config([
            'cibil.driver' => 'surepass',
            'cibil.surepass.base_url' => 'https://kyc-api.surepass.app',
            'cibil.surepass.token' => 'cibil-token-not-real',
            'cibil.experian.base_url' => 'https://kyc-api.surepass.app',
            'cibil.experian.token' => null,
            'cibil.experian.endpoints.full_report' => '/api/v1/credit-report-experian/fetch-report',
        ]);
        $this->app->forgetInstance(CibilClient::class);
        Http::preventStrayRequests();
        Http::fake([
            'kyc-api.surepass.app/api/v1/credit-report-experian/fetch-report' => Http::response([
                'success' => true,
                'status_code' => 200,
                'message_code' => 'success',
                'data' => ['client_id' => 'experian_ref_1', 'credit_score' => '768', 'credit_report' => ['account_summary' => ['total' => 3]]],
            ]),
        ]);

        // No Experian token: nothing is sent, and the CIBIL token is not borrowed.
        $missing = $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details(), bureau: CreditBureau::Experian)->report;

        $this->assertSame(CibilReportStatus::Failed, $missing->status);
        $this->assertSame('Experian credentials are not configured. Please contact the Admin.', $missing->error_message);
        Http::assertNothingSent();

        config(['cibil.experian.token' => 'experian-token-not-real', 'cibil.experian.endpoints.score' => null]);
        $this->app->forgetInstance(CibilClient::class);

        // An Experian request type with no endpoint yet: offered, but fails as "not connected" without sending.
        $notConnected = $this->service()->request($this->admin, CibilRequestType::Score, $this->details(), bureau: CreditBureau::Experian)->report;

        $this->assertSame(CibilReportStatus::Failed, $notConnected->status);
        $this->assertSame('The Experian API is not connected yet. Please contact the Admin.', $notConnected->error_message);
        Http::assertNothingSent();

        $report = $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details(), bureau: CreditBureau::Experian)->report->fresh();

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame(CreditBureau::Experian, $report->bureau);
        $this->assertSame(768, $report->score);
        $this->assertSame('experian_ref_1', $report->bureau_reference);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://kyc-api.surepass.app/api/v1/credit-report-experian/fetch-report'
            && $request->hasHeader('Authorization', 'Bearer experian-token-not-real')
            && $request['pan'] === 'MEENA1234I'
            && $request['consent'] === 'Y');
    }

    /**
     * Surepass's documented Experian APIs: the request body is pan, mobile,
     * name and consent (no gender), and the answers are read as documented —
     * the score, the report object, and the PDF behind a signed S3 link.
     */
    public function test_experian_uses_the_documented_surepass_score_report_and_pdf_apis(): void
    {
        $base = 'https://kyc-api.surepass.app/api/v1/credit-report-experian';
        $link = 'https://aadhaar-kyc-docs.s3.amazonaws.com/credit_report_experian/credit_report_1730202365240415.pdf?X-Amz-Signature=secret';

        $this->app->forgetInstance(CibilClient::class);
        config([
            'cibil.driver' => 'surepass',
            'cibil.experian.token' => 'experian-token-not-real',
            'cibil.experian.base_url' => 'https://kyc-api.surepass.app',
        ]);
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            "{$base}/score" => Http::response(['data' => [
                'client_id' => 'experian_credit_score_BLffggUeWtqOpHTjugpw',
                'name' => 'MEENA IYER', 'mobile' => '9811122233', 'pan' => 'MEENA1234I',
                'credit_score' => '796',
            ], 'status_code' => 200, 'success' => true, 'message' => 'Success', 'message_code' => 'success']),
            "{$base}/fetch-report" => Http::response(['data' => [
                'client_id' => 'credit_report_experian_SivVmssPcdDwdnhnqghz',
                'name' => 'Meena Iyer', 'mobile' => '9811122233', 'pan' => 'MEENA1234I',
                'credit_score' => '792',
                'credit_report' => [
                    'CreditProfileHeader' => ['ReportDate' => 20240705, 'Version' => 'V2.4'],
                    'CAIS_Account' => [
                        'CAIS_Summary' => ['Credit_Account' => ['CreditAccountTotal' => 44, 'CreditAccountActive' => 15]],
                        'CAIS_Account_DETAILS' => [['Subscriber_Name' => 'XXXXXXXXXX', 'Account_Type' => 13, 'Current_Balance' => 0]],
                    ],
                ],
            ], 'status_code' => 200, 'success' => true, 'message' => 'Success', 'message_code' => 'success']),
            "{$base}/fetch-report-pdf" => Http::response(['data' => [
                'client_id' => 'credit_report_experian_pdf_yzWulawpzCczmLyKazqJ',
                'name' => 'MEENA IYER', 'mobile' => '9811122233', 'pan' => 'MEENA1234I',
                'credit_score' => '761',
                'credit_report' => [],
                'credit_report_link' => $link,
            ], 'status_code' => 200, 'success' => true, 'message' => 'Success', 'message_code' => 'success']),
            'aadhaar-kyc-docs.s3.amazonaws.com/*' => Http::response("%PDF-1.4\nexperian report\n%%EOF\n", 200, ['Content-Type' => 'application/pdf']),
        ]);

        // Experian does not take gender, so none is needed or sent.
        $details = $this->details(['gender' => null]);
        $request = fn (CibilRequestType $type): CibilReport => $this->service()
            ->request($this->admin, $type, $details, bureau: CreditBureau::Experian)->report->fresh();

        $score = $request(CibilRequestType::Score);
        $full = $request(CibilRequestType::FullReport);
        $pdf = $request(CibilRequestType::Pdf);

        $this->assertSame([796, 792, 761], [$score->score, $full->score, $pdf->score]);
        $this->assertSame('experian_credit_score_BLffggUeWtqOpHTjugpw', $score->bureau_reference);
        $this->assertSame(44, $full->response_payload['credit_report'][0]['CAIS_Account']['CAIS_Summary']['Credit_Account']['CreditAccountTotal']);
        $this->assertNull($full->gender);
        Storage::disk('local')->assertExists($pdf->pdf_path);
        $this->assertArrayNotHasKey('credit_report_link', $pdf->response_payload);

        foreach (['score', 'fetch-report', 'fetch-report-pdf'] as $path) {
            Http::assertSent(fn (Request $sent): bool => $sent->url() === "{$base}/{$path}"
                && $sent->hasHeader('Authorization', 'Bearer experian-token-not-real')
                && $sent->data() === ['pan' => 'MEENA1234I', 'mobile' => '9811122233', 'name' => 'Meena Iyer', 'consent' => 'Y']);
        }
    }

    public function test_gender_is_required_for_cibil_but_not_for_experian(): void
    {
        $this->service()->request($this->admin, CibilRequestType::Score, $this->details(['gender' => null]), bureau: CreditBureau::Experian);

        $this->expectException(ValidationException::class);

        $this->service()->request($this->admin, CibilRequestType::Score, $this->details(['gender' => null, 'pan_number' => 'OTHER1234X']));
    }

    public function test_the_form_asks_for_gender_only_when_cibil_is_chosen(): void
    {
        $customer = $this->customer();

        Livewire::test(CreateCibilReport::class)
            ->fillForm(['bureau' => 'cibil', 'fetch_for' => 'customer'])
            ->fillForm(['customer_id' => $customer->id])
            ->fillForm(['request_type' => 'score', 'gender' => null, 'consent_method' => 'recorded_call', 'consent' => true])
            ->call('create')
            ->assertHasFormErrors(['gender' => 'required'])
            ->fillForm(['bureau' => 'experian'])
            ->call('create')
            ->assertHasNoFormErrors();

        $report = CibilReport::query()->sole();

        $this->assertSame(CreditBureau::Experian, $report->bureau);
        $this->assertNull($report->gender);
    }

    public function test_an_experian_report_on_a_lead_follows_the_lead_into_its_customer_file(): void
    {
        $owner = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $lead = Lead::create([
            'employee_id' => $owner->id,
            'customer_name' => 'Nisha Sharma',
            'mobile_no' => '8800618269',
            'pan_number' => 'BBBBB1212J',
            'follow_up_type' => 'Call',
            'status' => 'Interested',
            'remarks' => 'Wants a personal loan',
        ]);

        // Experian needs no gender; the lead's own details are used.
        Livewire::test(CibilProfileRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => EditLead::class])
            ->callAction(TestAction::make('fetchCibilReport')->table(), ['bureau' => 'experian', 'consent_method' => 'digital', 'consent' => true])
            ->assertHasNoActionErrors();

        $report = CibilReport::query()->sole();
        $this->assertSame(CreditBureau::Experian, $report->bureau);
        $this->assertSame($lead->id, $report->lead_id);
        $this->assertNull($report->gender);
        $this->assertSame(['experian:full_report'], $this->bureau->calls);

        // Conversion keeps the same row (nothing copied) and adds the customer link.
        $customer = Customer::factory()->create(['pan_number' => 'BBBBB1212J', 'assign_to' => $owner->id, 'employee_id' => $owner->id]);
        $this->assertSame(1, $this->service()->linkConvertedLead($customer, $lead->id));

        $report->refresh();
        $this->assertSame($lead->id, $report->lead_id);
        $this->assertSame($customer->id, $report->customer_id);
        $this->assertSame(1, CibilReport::query()->count());

        // The customer file's profile shows the Experian score and reuses the report.
        Livewire::test(CibilProfileRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->assertSee('Experian score:')
            ->assertSee((string) $report->score);

        $again = $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details(['customer_name' => 'Nisha Sharma', 'pan_number' => 'BBBBB1212J', 'mobile_no' => '8800618269']), $customer, bureau: CreditBureau::Experian);
        $this->assertTrue($again->reused);
        $this->assertSame(['experian:full_report'], $this->bureau->calls);
    }
}
