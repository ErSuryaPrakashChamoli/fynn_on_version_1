<?php

namespace Tests\Feature;

use App\Enums\CibilConsentMethod;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\CibilReport;
use App\Models\CibilSetting;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Services\Cibil\CibilApplicant;
use App\Services\Cibil\CibilClient;
use App\Services\Cibil\CibilFetchResult;
use App\Services\Cibil\CibilReportService;
use App\Services\Cibil\CibilRequestRefused;
use App\Services\Cibil\FakeCibilClient;
use App\Services\CustomerAssignmentService;
use App\Support\CibilAccess;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Billable-request safety: nothing goes to the bureau unless the Admin has
 * switched requests on, the customer's consent is recorded, the PAN is the
 * file's own, no equivalent request is in flight or uncertain, and the file
 * has no report that already answers it (unless a fresh pull is explicitly
 * asked for, by someone allowed to, with a reason). A lead's reports follow
 * it to the customer file it converts into, without being copied.
 */
class CibilRequestSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    private Employee $callerEmployee;

    /** @var object{calls: int} */
    private object $bureau;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

        // Counts every call that would have been billed.
        $this->bureau = new class implements CibilClient
        {
            public int $calls = 0;

            public function fetch(CibilApplicant $applicant, CibilRequestType $type, CreditBureau $bureau = CreditBureau::Cibil): CibilFetchResult
            {
                $this->calls++;

                return (new FakeCibilClient)->fetch($applicant, $type, $bureau);
            }
        };
        $this->app->instance(CibilClient::class, $this->bureau);

        CibilSetting::current()->update(['requests_enabled' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->callerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->caller = User::factory()->create(['employee_id' => $this->callerEmployee->id]);
        $this->caller->assignRole('Caller');
        CibilAccess::sync(['Caller'], []);
        CibilAccess::sync(['Caller'], [], CibilAccess::FULL_REPORT_PERMISSION);
        $this->caller = $this->caller->fresh();
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $this->callerEmployee->id,
            'employee_id' => $this->callerEmployee->id,
            'customer_name' => 'Meena Iyer',
            'pan_number' => 'MEENA1234I',
            'mobile_no' => '9811122233',
            ...$attributes,
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
            'consent_method' => 'written',
            'consent_reference' => 'FORM-0042',
            ...$overrides,
        ];
    }

    private function service(): CibilReportService
    {
        return app(CibilReportService::class);
    }

    // ---- Settings --------------------------------------------------------

    public function test_nothing_is_sent_while_requests_are_switched_off(): void
    {
        CibilSetting::current()->update(['requests_enabled' => false]);

        try {
            $this->service()->request($this->admin, CibilRequestType::Score, $this->details());
            $this->fail('Expected the request to be refused.');
        } catch (CibilRequestRefused $exception) {
            $this->assertStringContainsString('switched off', $exception->getMessage());
        }

        $this->assertSame(0, CibilReport::query()->count());
        $this->assertSame(0, $this->bureau->calls);
    }

    public function test_requests_start_switched_off_on_a_fresh_install(): void
    {
        $this->assertDatabaseHas('cibil_settings', ['id' => 1]);

        CibilSetting::query()->delete();

        $this->assertFalse(CibilSetting::current()->requests_enabled);
    }

    public function test_each_request_type_can_be_switched_off_on_its_own(): void
    {
        CibilSetting::current()->update(['pdf_enabled' => false]);

        $this->service()->request($this->admin, CibilRequestType::Score, $this->details());

        $this->expectException(CibilRequestRefused::class);
        $this->service()->request($this->admin, CibilRequestType::Pdf, $this->details());
    }

    // ---- Permissions -----------------------------------------------------

    public function test_each_request_type_needs_its_permission(): void
    {
        CibilAccess::sync([], [], CibilAccess::FULL_REPORT_PERMISSION);
        $caller = $this->caller->fresh();

        $this->assertTrue(CibilAccess::canRequest($caller, CibilRequestType::Score));
        $this->assertFalse(CibilAccess::canRequest($caller, CibilRequestType::FullReport));
        $this->assertFalse(CibilAccess::canRequest($caller, CibilRequestType::Pdf));

        $this->expectException(AuthorizationException::class);
        $this->service()->request($caller, CibilRequestType::FullReport, $this->details());
    }

    public function test_the_finer_permissions_count_only_with_module_access(): void
    {
        CibilAccess::sync([], []);

        $this->assertFalse(CibilAccess::canViewFullReport($this->caller->fresh()));
    }

    // ---- Consent and identity ------------------------------------------------

    public function test_consent_and_how_it_was_given_are_required_and_recorded(): void
    {
        foreach ([['consent' => false], ['consent_method' => null], ['consent_method' => 'by_telepathy']] as $missing) {
            try {
                $this->service()->request($this->admin, CibilRequestType::Score, $this->details($missing));
                $this->fail('Expected a validation error.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty(array_intersect(['consent', 'consent_method'], array_keys($exception->errors())));
            }
        }

        $this->assertSame(0, CibilReport::query()->count());

        $report = $this->service()->request($this->admin, CibilRequestType::Score, $this->details())->report;

        $this->assertSame(CibilConsentMethod::Written, $report->consent_method);
        $this->assertSame('FORM-0042', $report->consent_reference);
        $this->assertSame(CibilRequestType::Score->consentPurpose(), $report->consent_purpose);
        $this->assertNotNull($report->consent_given_at);
        $this->assertSame($this->admin->id, $report->requested_by);
    }

    public function test_a_request_for_a_file_must_use_the_files_own_pan(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->caller);

        try {
            $this->service()->request($this->caller, CibilRequestType::Score, $this->details(['pan_number' => 'OTHER1234X']), $customer);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pan_number', $exception->errors());
        }

        $this->assertSame(0, $this->bureau->calls);
    }

    // ---- Reuse -------------------------------------------------------------

    public function test_a_report_already_on_the_file_is_reused_instead_of_billed_again(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->caller);

        $first = $this->service()->request($this->caller, CibilRequestType::FullReport, $this->details(), $customer);
        $again = $this->service()->request($this->caller, CibilRequestType::FullReport, $this->details(), $customer);
        $score = $this->service()->request($this->caller, CibilRequestType::Score, $this->details(), $customer);

        $this->assertFalse($first->reused);
        $this->assertTrue($again->reused);
        $this->assertTrue($score->reused, 'A full report already carries the score.');
        $this->assertSame($first->report->id, $again->report->id);
        $this->assertSame($first->report->id, $score->report->id);
        $this->assertSame(1, $this->bureau->calls);
        $this->assertSame(1, CibilReport::query()->count());
        $this->assertSame(2, Activity::query()->where('event', 'reused')->count());
    }

    public function test_a_score_check_does_not_stand_in_for_a_full_report(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->caller);

        $this->service()->request($this->caller, CibilRequestType::Score, $this->details(), $customer);
        $full = $this->service()->request($this->caller, CibilRequestType::FullReport, $this->details(), $customer);

        $this->assertFalse($full->reused);
        $this->assertSame(2, $this->bureau->calls);
        $this->assertSame('No earlier CIBIL Full report on this file.', $full->report->request_reason);
    }

    public function test_reuse_is_per_file_never_by_pan_alone(): void
    {
        $customer = $this->customer();
        $otherFile = $this->customer(['customer_name' => 'Meena Iyer (duplicate file)']);
        $this->actingAs($this->caller);

        $this->service()->request($this->caller, CibilRequestType::Score, $this->details(), $customer);
        $other = $this->service()->request($this->caller, CibilRequestType::Score, $this->details(), $otherFile);

        $this->assertFalse($other->reused);
        $this->assertSame($otherFile->id, $other->report->customer_id);
    }

    public function test_the_reuse_window_lets_an_older_report_go_stale(): void
    {
        CibilSetting::current()->update(['reuse_window_days' => 30]);
        $customer = $this->customer();
        CibilReport::factory()->score()->create([
            'customer_id' => $customer->id,
            'pan_number' => 'MEENA1234I',
            'fetched_at' => now()->subDays(45),
        ]);
        $this->actingAs($this->caller);

        $outcome = $this->service()->request($this->caller, CibilRequestType::Score, $this->details(), $customer);

        $this->assertFalse($outcome->reused);
        $this->assertSame(1, $this->bureau->calls);
        $this->assertStringContainsString('older than the 30-day reuse window', $outcome->report->request_reason);
    }

    public function test_a_fresh_pull_needs_the_permission_and_a_reason_and_skips_reuse(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->caller);
        $this->service()->request($this->caller, CibilRequestType::Score, $this->details(), $customer);

        try {
            $this->service()->request($this->caller, CibilRequestType::Score, $this->details(['reason' => 'Loan closed']), $customer, fresh: true);
            $this->fail('Expected the fresh pull to need the permission.');
        } catch (AuthorizationException) {
        }

        CibilAccess::sync(['Caller'], [], CibilAccess::REFRESH_PERMISSION);
        $caller = $this->caller->fresh();

        try {
            $this->service()->request($caller, CibilRequestType::Score, $this->details(), $customer, fresh: true);
            $this->fail('Expected a reason to be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $fresh = $this->service()->request($caller, CibilRequestType::Score, $this->details(['reason' => 'Loan closed last week']), $customer, fresh: true);

        $this->assertFalse($fresh->reused);
        $this->assertSame(2, $this->bureau->calls);
        $this->assertSame('Fresh pull requested: Loan closed last week', $fresh->report->request_reason);
    }

    public function test_fresh_pulls_can_be_switched_off_by_the_admin(): void
    {
        CibilSetting::current()->update(['fresh_requests_enabled' => false]);

        $this->expectException(CibilRequestRefused::class);
        $this->service()->request($this->admin, CibilRequestType::Score, $this->details(['reason' => 'Need a new one']), fresh: true);
    }

    // ---- Duplicates, concurrency, uncertain outcomes ----------------------

    public function test_a_request_still_in_flight_blocks_an_equivalent_one(): void
    {
        CibilReport::factory()->score()->pending()->create(['pan_number' => 'MEENA1234I', 'created_at' => now()->subMinute()]);

        try {
            $this->service()->request($this->admin, CibilRequestType::Score, $this->details());
            $this->fail('Expected the request to be refused.');
        } catch (CibilRequestRefused $exception) {
            $this->assertStringContainsString('still processing', $exception->getMessage());
        }

        // A different request type for the same PAN is a different call.
        $this->service()->request($this->admin, CibilRequestType::FullReport, $this->details());

        $this->assertSame(1, $this->bureau->calls);
    }

    public function test_a_request_left_pending_too_long_becomes_uncertain_and_blocks_until_verified(): void
    {
        $stale = CibilReport::factory()->score()->pending()->create(['pan_number' => 'MEENA1234I', 'created_at' => now()->subHour()]);

        try {
            $this->service()->request($this->admin, CibilRequestType::Score, $this->details());
            $this->fail('Expected the request to be refused.');
        } catch (CibilRequestRefused $exception) {
            $this->assertSame('The request outcome is uncertain; verify its status before retrying.', $exception->getMessage());
            $this->assertSame($stale->id, $exception->blockingReport?->id);
        }

        $this->assertSame(CibilReportStatus::Uncertain, $stale->fresh()->status);
        $this->assertSame(0, $this->bureau->calls);
    }

    public function test_concurrent_requests_for_the_same_pan_are_refused_while_one_holds_the_lock(): void
    {
        $lock = Cache::lock('cibil-request:'.hash('sha256', 'MEENA1234I|cibil|score'), 30);
        $this->assertTrue($lock->get());

        try {
            $this->service()->request($this->admin, CibilRequestType::Score, $this->details());
            $this->fail('Expected the request to be refused.');
        } catch (CibilRequestRefused $exception) {
            $this->assertStringContainsString('still processing', $exception->getMessage());
        } finally {
            $lock->release();
        }

        $this->assertSame(0, CibilReport::query()->count());
        $this->assertSame(0, $this->bureau->calls);
    }

    public function test_after_an_uncertain_outcome_only_a_verified_retry_goes_ahead(): void
    {
        $uncertain = CibilReport::factory()->score()->uncertain()->create(['pan_number' => 'MEENA1234I']);

        try {
            $this->service()->request($this->caller, CibilRequestType::Score, $this->details());
            $this->fail('Expected the request to be refused.');
        } catch (CibilRequestRefused $exception) {
            $this->assertStringContainsString('verify its status before retrying', $exception->getMessage());
        }

        // Acknowledging needs the fresh-pull permission and a reason.
        try {
            $this->service()->request($this->caller, CibilRequestType::Score, $this->details(['after_uncertain' => true, 'reason' => 'Verified']));
            $this->fail('Expected the acknowledgement to need the permission.');
        } catch (AuthorizationException) {
        }

        CibilAccess::sync(['Caller'], [], CibilAccess::REFRESH_PERMISSION);

        $retry = $this->service()->request($this->caller->fresh(), CibilRequestType::Score, $this->details(['after_uncertain' => true, 'reason' => 'Checked with Surepass support: not processed']));

        $this->assertSame(CibilReportStatus::Success, $retry->report->status);
        $this->assertStringStartsWith("Sent after uncertain request #{$uncertain->id} was verified", $retry->report->request_reason);

        $uncertain->refresh();
        $this->assertSame(CibilReportStatus::Uncertain, $uncertain->status, 'The uncertain request keeps its status.');
        $this->assertNotNull($uncertain->resolved_at);
        $this->assertSame($this->caller->id, $uncertain->resolved_by);
    }

    public function test_only_the_admin_marks_an_uncertain_request_verified(): void
    {
        $uncertain = CibilReport::factory()->score()->uncertain()->create(['pan_number' => 'MEENA1234I']);

        try {
            $this->service()->resolveUncertain($this->caller, $uncertain, 'Looks fine');
            $this->fail('Expected only the Admin to verify.');
        } catch (AuthorizationException) {
        }

        $this->service()->resolveUncertain($this->admin, $uncertain, 'Checked the provider dashboard: not billed.');

        $this->assertFalse($uncertain->fresh()->isUnresolvedUncertain());
        $this->assertSame(CibilReportStatus::Success, $this->service()->request($this->admin, CibilRequestType::Score, $this->details())->report->status);
        $this->assertTrue(Activity::query()->where('event', 'uncertain_resolved')->where('subject_id', $uncertain->id)->exists());
    }

    // ---- Lead conversion ---------------------------------------------------

    public function test_converting_a_lead_links_its_reports_to_the_new_customer_without_copying_them(): void
    {
        $lead = Lead::create([
            'employee_id' => $this->callerEmployee->id,
            'customer_name' => 'Meena Iyer',
            'mobile_no' => '9811122233',
            'pan_number' => 'MEENA1234I',
            'follow_up_type' => 'Call',
            'status' => 'Eligible',
            'remarks' => 'Ready to convert',
        ]);
        $leadReport = CibilReport::factory()->create(['lead_id' => $lead->id, 'pan_number' => 'MEENA1234I']);
        $otherPan = CibilReport::factory()->create(['lead_id' => $lead->id, 'pan_number' => 'OTHER1234X']);
        $unrelated = CibilReport::factory()->create(['pan_number' => 'MEENA1234I']);

        $this->actingAs($this->admin);
        $customer = $this->customer();

        // The real conversion hook: CreateCustomer::afterCreate() for ?lead=…
        Livewire::withQueryParams(['lead' => $lead->id]);
        $page = Livewire::test(CreateCustomer::class)->instance();
        $page->record = $customer;
        (new ReflectionMethod($page, 'afterCreate'))->invoke($page);

        $this->assertTrue($lead->fresh()->is_converted);
        $this->assertSame($customer->id, $leadReport->fresh()->customer_id);
        $this->assertSame($lead->id, $leadReport->fresh()->lead_id);
        $this->assertNull($otherPan->fresh()->customer_id, 'A report for another PAN is not linked.');
        $this->assertNull($unrelated->fresh()->customer_id, 'Never matched up by PAN alone.');
        $this->assertSame(3, CibilReport::query()->count());
        $this->assertSame([$leadReport->id], $customer->cibilReports()->pluck('id')->all());

        // …and the customer file now reuses it.
        $this->actingAs($this->caller);
        $outcome = $this->service()->request($this->caller, CibilRequestType::Score, $this->details(), $customer);
        $this->assertTrue($outcome->reused);
        $this->assertSame($leadReport->id, $outcome->report->id);
    }

    public function test_converting_an_assigned_lead_links_the_assignments_reports(): void
    {
        $customer = $this->customer();
        $template = AiDocumentSchema::create(['name' => 'Axis Template Data', 'is_active' => true, 'fields' => []]);
        $record = AiCustomerRecord::create([
            'schema_id' => $template->id,
            'status' => 'approved',
            'data' => ['customer_name' => 'Meena Iyer', 'mobile_number' => '9811122233', 'pan_number' => 'MEENA1234I'],
        ]);
        app(CustomerAssignmentService::class)->assign(collect([$record->id]), $this->callerEmployee->id, CustomerAssignmentService::TARGET_AI_RECORD, null);
        $assignment = CustomerAssignment::query()->where('ai_customer_record_id', $record->id)->sole();
        $report = CibilReport::factory()->create(['customer_assignment_id' => $assignment->id, 'pan_number' => 'MEENA1234I']);

        $linked = $this->service()->linkConvertedLead($customer, null, [$assignment->id], $this->admin);

        $this->assertSame(1, $linked);
        $this->assertSame($customer->id, $report->fresh()->customer_id);
        $this->assertSame($assignment->id, $report->fresh()->customer_assignment_id);
    }
}
