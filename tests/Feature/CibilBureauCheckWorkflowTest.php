<?php

namespace Tests\Feature;

use App\Enums\BureauDecision;
use App\Enums\CibilReportStatus;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\CibilReports\Pages\CreateCibilReport;
use App\Filament\Resources\CibilReports\Pages\ListCibilReports;
use App\Filament\Resources\CibilReports\Pages\ViewCibilReport;
use App\Filament\Resources\Customers\CustomerResource;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\CibilReport;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\Cibil\CibilClient;
use App\Services\Cibil\CibilReportService;
use App\Services\Cibil\FakeCibilClient;
use App\Services\CustomerAssignmentService;
use App\Support\BureauCheckCandidates;
use App\Support\CibilAccess;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The bureau check: a lead (My Leads or Assigned Leads) is marked "Bureau
 * Check", someone with CIBIL access fetches its report from the CIBIL form,
 * and the manager marks it Eligible or Not Eligible, which moves the lead to
 * that status; Interested, Eligible and Eligible for Other Bank leads can be
 * converted to a customer. A report is always fetched for a lead or a
 * customer, and the listing shows one row per applicant.
 */
class CibilBureauCheckWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    private Employee $callerEmployee;

    private Employee $otherEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Caller', 'Manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->app->instance(CibilClient::class, new FakeCibilClient);
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

        $this->admin = User::factory()->create(['name' => 'Asha Admin']);
        $this->admin->assignRole('Admin');

        $this->callerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->caller = User::factory()->create(['name' => 'Ravi Caller', 'employee_id' => $this->callerEmployee->id]);
        $this->caller->assignRole('Caller');
        CibilAccess::sync(['Caller'], []);
        $this->caller = $this->caller->fresh();

        $this->otherEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(array $attributes = []): Lead
    {
        return Lead::create([
            'employee_id' => $this->callerEmployee->id,
            'customer_name' => 'Nisha Sharma',
            'mobile_no' => '8800618269',
            'pan_number' => 'BBBBB1212J',
            'follow_up_type' => 'Call',
            'status' => CustomerAssignment::BUREAU_CHECK_STATUS,
            'remarks' => 'Send for CIBIL',
            'next_follow_up_date' => now()->addDays(2),
            ...$attributes,
        ]);
    }

    private function assignedLead(string $status, string $name = 'Sunil Verma', ?int $employeeId = null): CustomerAssignment
    {
        $template = AiDocumentSchema::create(['name' => 'Axis Template Data', 'is_active' => true, 'fields' => []]);
        $record = AiCustomerRecord::create([
            'schema_id' => $template->id,
            'status' => 'approved',
            'data' => ['customer_name' => $name, 'mobile_number' => '9123456780', 'pan_number' => 'lmnop4321q'],
        ]);

        $employeeId ??= $this->callerEmployee->id;

        app(CustomerAssignmentService::class)->assign(collect([$record->id]), $employeeId, CustomerAssignmentService::TARGET_AI_RECORD, null);

        FollowUp::create([
            'ai_customer_record_id' => $record->id,
            'employee_id' => $employeeId,
            'follow_up_type' => 'Call',
            'status' => $status,
            'remarks' => 'Spoke to the prospect',
            'next_follow_up_date' => now()->addDay(),
        ]);

        return CustomerAssignment::query()->where('ai_customer_record_id', $record->id)->sole();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reportFor(Lead|CustomerAssignment $lead, array $attributes = []): CibilReport
    {
        return CibilReport::factory()->create([
            'requested_by' => $this->caller->id,
            'lead_id' => $lead instanceof Lead ? $lead->id : null,
            'customer_assignment_id' => $lead instanceof CustomerAssignment ? $lead->id : null,
            'pan_number' => 'BBBBB1212J',
            'score' => 742,
            ...$attributes,
        ]);
    }

    // ---- Required details -------------------------------------------------

    public function test_name_pan_and_date_of_birth_are_required_and_everything_else_is_optional(): void
    {
        $report = app(CibilReportService::class)->fetch($this->admin, [
            'customer_name' => 'Nisha Sharma',
            'pan_number' => 'bbbbb1212j',
            'date_of_birth' => '1994-03-09',
            'consent' => true,
        ]);

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame('BBBBB1212J', $report->pan_number);
        $this->assertNull($report->mobile_no);
        $this->assertSame('1994-03-09', $report->date_of_birth->toDateString());

        try {
            app(CibilReportService::class)->fetch($this->admin, ['consent' => true]);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(['customer_name', 'pan_number', 'date_of_birth'], array_keys($exception->errors()));
        }
    }

    public function test_the_form_requires_a_lead_or_a_customer_and_the_date_of_birth(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateCibilReport::class)
            ->fillForm(['customer_name' => 'Nisha Sharma', 'pan_number' => 'BBBBB1212J', 'consent' => true])
            ->call('create')
            ->assertHasFormErrors(['fetch_for' => 'required', 'date_of_birth' => 'required'])
            ->assertHasNoFormErrors(['mobile_no', 'email', 'gender', 'pincode']);

        $this->assertSame(0, CibilReport::query()->count());
    }

    // ---- Lead and customer pickers ----------------------------------------

    public function test_the_lead_picker_lists_only_visible_bureau_check_leads_from_both_listings(): void
    {
        $waiting = $this->lead(['customer_name' => 'Nisha Sharma']);
        $interested = $this->lead(['customer_name' => 'Imran Interested', 'status' => 'Interested']);
        $someoneElses = $this->lead(['customer_name' => 'Other Owner', 'employee_id' => $this->otherEmployee->id]);
        $converted = $this->lead(['customer_name' => 'Already Converted', 'is_converted' => true]);
        $waitingAssigned = $this->assignedLead(CustomerAssignment::BUREAU_CHECK_STATUS, 'Sunil Verma');
        $interestedAssigned = $this->assignedLead('Interested', 'Kavya Interested');

        $this->actingAs($this->caller);

        $options = BureauCheckCandidates::leadOptions();

        $this->assertSame(['My Leads', 'Assigned Leads'], array_keys($options));
        $this->assertSame(['lead:'.$waiting->id => 'Nisha Sharma · BBBBB1212J'], $options['My Leads']);
        $this->assertSame(['assigned:'.$waitingAssigned->id => 'Sunil Verma · LMNOP4321Q'], $options['Assigned Leads']);

        foreach (['lead:'.$interested->id, 'lead:'.$someoneElses->id, 'lead:'.$converted->id, 'assigned:'.$interestedAssigned->id, 'lead:abc', 'customer:1'] as $key) {
            $this->assertNull(BureauCheckCandidates::resolveLead($key), $key);
        }
    }

    public function test_the_customer_picker_lists_customers_without_a_fetched_report(): void
    {
        $fresh = Customer::factory()->create(['assign_to' => $this->callerEmployee->id, 'employee_id' => $this->callerEmployee->id, 'pan_number' => 'AAAAA1111A']);
        $onlyFailed = Customer::factory()->create(['assign_to' => $this->callerEmployee->id, 'employee_id' => $this->callerEmployee->id, 'pan_number' => 'BBBBB2222B']);
        $linked = Customer::factory()->create(['assign_to' => $this->callerEmployee->id, 'employee_id' => $this->callerEmployee->id, 'pan_number' => 'CCCCC3333C']);
        $samePan = Customer::factory()->create(['assign_to' => $this->callerEmployee->id, 'employee_id' => $this->callerEmployee->id, 'pan_number' => 'DDDDD4444D']);
        $someoneElses = Customer::factory()->create(['assign_to' => $this->otherEmployee->id, 'employee_id' => $this->otherEmployee->id, 'pan_number' => 'EEEEE5555E']);

        CibilReport::factory()->failed()->create(['customer_id' => $onlyFailed->id, 'pan_number' => 'BBBBB2222B']);
        CibilReport::factory()->create(['customer_id' => $linked->id, 'pan_number' => 'CCCCC3333C']);
        CibilReport::factory()->create(['pan_number' => 'DDDDD4444D']);

        $this->actingAs($this->caller);

        $this->assertEqualsCanonicalizing([$fresh->id, $onlyFailed->id], array_keys(BureauCheckCandidates::pendingCustomerOptions()));

        // Typing still finds any of the user's own files, fetched or not.
        $this->assertSame([$linked->id], array_keys(BureauCheckCandidates::searchCustomers('CCCCC3333C')));
        $this->assertSame([], BureauCheckCandidates::searchCustomers('EEEEE5555E'));
        $this->assertNull(BureauCheckCandidates::resolveCustomer($someoneElses->id));
    }

    public function test_fetching_for_a_my_lead_fills_its_details_and_links_the_report(): void
    {
        $lead = $this->lead(['email' => 'nisha@example.com']);

        $this->actingAs($this->caller);

        $page = Livewire::test(CreateCibilReport::class)
            ->fillForm(['fetch_for' => 'lead'])
            ->fillForm(['lead_key' => 'lead:'.$lead->id])
            ->assertSchemaStateSet([
                'customer_name' => 'Nisha Sharma',
                'pan_number' => 'BBBBB1212J',
                'mobile_no' => '8800618269',
                'email' => 'nisha@example.com',
            ])
            ->fillForm(['date_of_birth' => '1994-03-09', 'consent' => true])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('CIBIL report fetched');

        $report = CibilReport::query()->sole();
        $page->assertRedirect(CibilReportResource::getUrl('view', ['record' => $report]));

        $this->assertSame($lead->id, $report->lead_id);
        $this->assertNull($report->customer_id);
        $this->assertNull($report->customer_assignment_id);
    }

    public function test_fetching_for_an_assigned_lead_links_the_assignment(): void
    {
        $assigned = $this->assignedLead(CustomerAssignment::BUREAU_CHECK_STATUS);

        $this->actingAs($this->caller);

        Livewire::test(CreateCibilReport::class)
            ->fillForm(['fetch_for' => 'lead'])
            ->fillForm(['lead_key' => 'assigned:'.$assigned->id])
            ->assertSchemaStateSet(['customer_name' => 'Sunil Verma', 'pan_number' => 'LMNOP4321Q', 'mobile_no' => '9123456780'])
            ->fillForm(['date_of_birth' => '1990-01-20', 'consent' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($assigned->id, CibilReport::query()->sole()->customer_assignment_id);
    }

    public function test_the_form_refuses_a_lead_that_is_not_waiting_for_a_bureau_check(): void
    {
        $interested = $this->lead(['status' => 'Interested']);

        $this->actingAs($this->caller);

        Livewire::test(CreateCibilReport::class)
            ->fillForm(['fetch_for' => 'lead', 'lead_key' => 'lead:'.$interested->id, 'customer_name' => 'Nisha Sharma', 'pan_number' => 'BBBBB1212J', 'date_of_birth' => '1994-03-09', 'consent' => true])
            ->call('create')
            ->assertHasFormErrors(['lead_key']);

        $this->assertSame(0, CibilReport::query()->count());
    }

    // ---- Listing ----------------------------------------------------------

    public function test_the_listing_shows_one_row_per_applicant_with_how_often_it_was_fetched(): void
    {
        $this->actingAs($this->admin);

        $fetched = CibilReport::factory()->create(['requested_by' => $this->caller->id, 'pan_number' => 'BBBBB1212J', 'score' => 742]);
        $laterFailure = CibilReport::factory()->failed()->create(['requested_by' => $this->caller->id, 'pan_number' => 'BBBBB1212J']);
        $byAdmin = CibilReport::factory()->failed()->create(['requested_by' => $this->admin->id, 'pan_number' => 'BBBBB1212J']);
        $onlyFailures = CibilReport::factory()->failed()->create(['requested_by' => $this->caller->id, 'pan_number' => 'ZZZZZ9999Z']);
        $newestFailure = CibilReport::factory()->failed()->create(['requested_by' => $this->caller->id, 'pan_number' => 'ZZZZZ9999Z']);

        // The applicant's newest fetched report stands for them, even when
        // a later pull failed; with no fetched report, the newest attempt.
        Livewire::test(ListCibilReports::class)
            ->assertCanSeeTableRecords([$fetched, $newestFailure])
            ->assertCanNotSeeTableRecords([$laterFailure, $byAdmin, $onlyFailures])
            ->assertCountTableRecords(2)
            ->assertTableColumnStateSet('fetch_count', 3, $fetched)
            ->assertTableColumnStateSet('fetch_count', 2, $newestFailure)
            ->sortTable('fetch_count', 'desc')
            ->assertCanSeeTableRecords([$fetched, $newestFailure], inOrder: true);

        // Anyone else counts only the pulls they can see — their own.
        $this->actingAs($this->caller);

        Livewire::test(ListCibilReports::class)
            ->assertCountTableRecords(2)
            ->assertTableColumnStateSet('fetch_count', 2, $fetched);
    }

    // ---- Bureau decision --------------------------------------------------

    public function test_marking_a_lead_eligible_moves_it_to_eligible_and_offers_conversion(): void
    {
        $lead = $this->lead();
        $report = $this->reportFor($lead);

        $this->actingAs($this->caller);

        Livewire::test(ViewCibilReport::class, ['record' => $report->getRouteKey()])
            ->assertSee('Mark the lead Eligible or Not Eligible')
            ->assertActionVisible('markEligible')
            ->assertActionVisible('markNotEligible')
            ->assertActionHidden('convertToCustomer')
            ->callAction(TestAction::make('markEligible'), ['remarks' => 'Clean repayment history'])
            ->assertHasNoActionErrors()
            ->assertNotified('Lead marked Eligible')
            ->assertActionHidden('markEligible')
            ->assertActionHidden('markNotEligible')
            ->assertActionVisible('convertToCustomer');

        $report->refresh();
        $this->assertSame(BureauDecision::Eligible, $report->bureau_decision);
        $this->assertSame($this->caller->id, $report->decided_by);
        $this->assertNotNull($report->decided_at);
        $this->assertSame('Clean repayment history', $report->decision_remarks);

        $lead->refresh();
        $this->assertSame('Eligible', $lead->status);
        $this->assertNotNull($lead->next_follow_up_date);
        $this->assertTrue($lead->isConvertible());

        $followUp = $lead->followUps()->latest('id')->first();
        $this->assertSame('Eligible', $followUp->status);
        $this->assertSame('Bureau check: Eligible (CIBIL score 742). Clean repayment history', $followUp->remarks);

        $this->assertTrue(Activity::query()->where('event', 'bureau_decision')->where('subject_id', $report->id)->where('causer_id', $this->caller->id)->exists());
    }

    public function test_marking_a_lead_not_eligible_closes_its_follow_ups_and_blocks_conversion(): void
    {
        $lead = $this->lead();
        $report = $this->reportFor($lead, ['score' => 540]);

        $this->actingAs($this->caller);

        Livewire::test(ViewCibilReport::class, ['record' => $report->getRouteKey()])
            ->callAction(TestAction::make('markNotEligible'))
            ->assertHasNoActionErrors()
            ->assertActionHidden('convertToCustomer');

        $lead->refresh();
        $this->assertSame('Not Eligible', $lead->status);
        $this->assertNull($lead->next_follow_up_date);
        $this->assertFalse($lead->isConvertible());
        $this->assertSame(BureauDecision::NotEligible, $report->fresh()->bureau_decision);
    }

    public function test_marking_an_assigned_lead_eligible_records_a_follow_up_and_allows_conversion(): void
    {
        $assigned = $this->assignedLead(CustomerAssignment::BUREAU_CHECK_STATUS);
        $report = $this->reportFor($assigned);

        $this->actingAs($this->caller);

        Livewire::test(ViewCibilReport::class, ['record' => $report->getRouteKey()])
            ->callAction(TestAction::make('markEligible'))
            ->assertHasNoActionErrors()
            ->assertActionVisible('convertToCustomer')
            ->callAction(TestAction::make('convertToCustomer'))
            ->assertRedirect(CustomerResource::getUrl('create', ['ai_customer_record' => $assigned->ai_customer_record_id]));

        $assigned->refresh();
        $this->assertSame('Eligible', $assigned->latestFollowUpStatus());
        $this->assertSame($this->callerEmployee->id, $assigned->latestFollowUp()->employee_id);
        $this->assertTrue($assigned->isEligibleForConversion());
    }

    public function test_no_decision_is_asked_for_a_failed_report_a_customer_report_or_a_lead_that_moved_on(): void
    {
        $service = app(CibilReportService::class);

        $failed = $this->reportFor($this->lead(), ['status' => CibilReportStatus::Failed, 'score' => null]);
        $customerReport = CibilReport::factory()->create(['requested_by' => $this->caller->id, 'customer_id' => Customer::factory()->create()->id]);
        $movedOn = $this->reportFor($this->lead(['status' => 'Interested']));

        foreach ([$failed, $customerReport, $movedOn] as $report) {
            $this->assertFalse($service->canDecide($report, $this->caller));
        }

        $this->actingAs($this->caller);

        Livewire::test(ViewCibilReport::class, ['record' => $failed->getRouteKey()])
            ->assertActionHidden('markEligible')
            ->assertSee('No decision can be made until the report is fetched');

        $this->expectException(AuthorizationException::class);
        $service->decide($this->caller, $movedOn, BureauDecision::Eligible);
    }

    public function test_the_decision_is_recorded_only_once(): void
    {
        $lead = $this->lead();
        $report = $this->reportFor($lead);
        $service = app(CibilReportService::class);

        $service->decide($this->caller, $report, BureauDecision::Eligible);

        // Even if the lead is sent back for another bureau check, this report
        // keeps its decision; a new pull gets its own.
        $lead->refresh()->update(['status' => CustomerAssignment::BUREAU_CHECK_STATUS]);

        $this->assertFalse($service->canDecide($report->fresh(), $this->caller));
        $this->assertTrue($service->canDecide($this->reportFor($lead), $this->caller));
    }

    public function test_a_login_without_cibil_access_cannot_decide(): void
    {
        $report = $this->reportFor($this->lead());

        CibilAccess::sync([], []);

        $this->assertFalse(app(CibilReportService::class)->canDecide($report, $this->caller->fresh()));
    }

    // ---- Conversion -------------------------------------------------------

    public function test_interested_eligible_and_other_bank_leads_can_be_converted(): void
    {
        foreach (['Interested', 'Eligible', 'Eligible for Other Bank'] as $status) {
            $this->assertTrue($this->lead(['status' => $status])->isConvertible(), $status);
            $this->assertTrue($this->assignedLead($status)->isEligibleForConversion(), $status);
        }

        foreach ([CustomerAssignment::BUREAU_CHECK_STATUS, 'Pending', 'Not Eligible'] as $status) {
            $this->assertFalse($this->lead(['status' => $status])->isConvertible(), $status);
            $this->assertFalse($this->assignedLead($status)->isEligibleForConversion(), $status);
        }

        $this->assertFalse($this->lead(['status' => 'Eligible', 'is_converted' => true])->isConvertible());
    }

    public function test_converting_a_lead_from_the_report_asks_for_missing_details_first(): void
    {
        $incomplete = $this->lead(['status' => 'Eligible']);
        $complete = $this->lead([
            'status' => 'Eligible',
            'email' => 'nisha@example.com',
            'salary' => '85000',
            'job_location' => 'Noida',
            'current_location' => 'Delhi',
            'residence_location' => 'Delhi',
        ]);

        $this->assertSame(['Job Location', 'Salary', 'Email', 'Residence Location', 'Current Location'], $incomplete->missingConversionFields());
        $this->assertSame([], $complete->missingConversionFields());

        $this->actingAs($this->caller);

        Livewire::test(ViewCibilReport::class, ['record' => $this->reportFor($incomplete)->getRouteKey()])
            ->callAction(TestAction::make('convertToCustomer'))
            ->assertNotified('Lead cannot be converted')
            ->assertNoRedirect();

        Livewire::test(ViewCibilReport::class, ['record' => $this->reportFor($complete)->getRouteKey()])
            ->callAction(TestAction::make('convertToCustomer'))
            ->assertRedirect(CustomerResource::getUrl('create', ['lead' => $complete->id]));
    }

    public function test_bureau_check_and_eligible_are_offered_as_lead_statuses(): void
    {
        $this->assertArrayHasKey('Bureau Check', CustomerAssignment::FOLLOW_UP_STATUSES);
        $this->assertArrayHasKey('Eligible', CustomerAssignment::FOLLOW_UP_STATUSES);
        $this->assertNotContains('Bureau Check', CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES);
    }

    // ---- Fetch again ------------------------------------------------------

    public function test_fetch_again_asks_for_the_date_of_birth_an_older_report_lacks_and_keeps_the_link(): void
    {
        $lead = $this->lead();
        $old = $this->reportFor($lead, ['date_of_birth' => null]);

        $this->actingAs($this->caller);

        Livewire::test(ViewCibilReport::class, ['record' => $old->getRouteKey()])
            ->callAction(TestAction::make('fetchAgain'), ['consent' => true])
            ->assertHasActionErrors(['date_of_birth' => 'required']);

        $this->assertSame(1, CibilReport::query()->count());

        Livewire::test(ViewCibilReport::class, ['record' => $old->getRouteKey()])
            ->callAction(TestAction::make('fetchAgain'), ['consent' => true, 'date_of_birth' => '1994-03-09'])
            ->assertHasNoActionErrors();

        $new = CibilReport::query()->latest('id')->first();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('1994-03-09', $new->date_of_birth->toDateString());
        $this->assertSame($lead->id, $new->lead_id);
    }
}
