<?php

namespace Tests\Feature;

use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Filament\Resources\AssignedLeads\Pages\ViewAssignedLead;
use App\Filament\Resources\CibilReports\Pages\ViewCibilReport;
use App\Filament\Resources\CibilReports\RelationManagers\CibilProfileRelationManager;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\EditLead;
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
use App\Services\Cibil\FakeCibilClient;
use App\Services\CustomerAssignmentService;
use App\Support\CibilAccess;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The CIBIL Credit Profile on a customer file (view page), a My Leads lead
 * (its edit page) and an assigned lead (view page): who sees it, what the
 * request actions fill in from the file, consent and missing details,
 * reuse and duplicate refusal, and that each report stays with its file.
 */
class CibilCustomerLeadProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $caller;

    private User $manager;

    private User $outsider;

    private Employee $callerEmployee;

    private Employee $otherEmployee;

    /** @var object{calls: int} */
    private object $bureau;

    /** @var array<string, mixed> */
    private array $consent = ['gender' => 'female', 'consent_method' => 'recorded_call', 'consent' => true];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Caller', 'Manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

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

        $managerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'exit_status' => 'no']);
        $this->manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
        $this->manager->assignRole('Manager');

        $this->callerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no', 'manager_id' => $managerEmployee->id]);
        $this->caller = User::factory()->create(['employee_id' => $this->callerEmployee->id]);
        $this->caller->assignRole('Caller');

        $this->otherEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->outsider = User::factory()->create(['employee_id' => $this->otherEmployee->id]);
        $this->outsider->assignRole('Caller');

        // Callers and Managers may use the module; full reports, PDFs and fresh pulls are not granted.
        CibilAccess::sync(['Caller', 'Manager'], []);
        $this->caller = $this->caller->fresh();
        $this->manager = $this->manager->fresh();
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

    private function lead(array $attributes = []): Lead
    {
        return Lead::create([
            'employee_id' => $this->callerEmployee->id,
            'customer_name' => 'Nisha Sharma',
            'mobile_no' => '8800618269',
            'pan_number' => 'BBBBB1212J',
            'follow_up_type' => 'Call',
            'status' => 'Interested',
            'remarks' => 'Wants a personal loan',
            ...$attributes,
        ]);
    }

    private function profile(Customer|Lead|CustomerAssignment $owner, string $pageClass): mixed
    {
        return Livewire::test(CibilProfileRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => $pageClass]);
    }

    // ---- Customer file -----------------------------------------------------

    public function test_the_profile_shows_on_the_customer_view_page_only_with_cibil_access(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->caller);
        $this->assertTrue(CibilProfileRelationManager::canViewForRecord($customer, ViewCustomer::class));
        $this->assertFalse(CibilProfileRelationManager::canViewForRecord($customer, EditCustomer::class));
        $this->assertContains(CibilProfileRelationManager::class, CustomerResource::getRelations());

        CibilAccess::sync([], []);
        $this->actingAs($this->caller->fresh());
        $this->assertFalse(CibilProfileRelationManager::canViewForRecord($customer, ViewCustomer::class));
    }

    public function test_opening_the_profile_never_contacts_the_bureau_and_masks_pan_and_mobile(): void
    {
        $customer = $this->customer();
        CibilReport::factory()->score()->create(['customer_id' => $customer->id, 'pan_number' => 'MEENA1234I', 'score' => 781, 'requested_by' => $this->caller->id]);

        $this->actingAs($this->caller);

        $this->profile($customer, ViewCustomer::class)
            ->assertOk()
            ->assertSee('CIBIL Credit Profile')
            ->assertSee('MEXXXXXX4I')
            ->assertSee('XXXXXX2233')
            ->assertSee('781')
            ->assertDontSee('MEENA1234I')
            ->assertDontSee('9811122233');

        $this->assertSame(0, $this->bureau->calls);
    }

    public function test_an_authorised_employee_checks_a_customers_score_with_the_files_details(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->caller);

        $this->profile($customer, ViewCustomer::class)
            ->mountAction(TestAction::make('checkCibilScore')->table())
            ->assertActionDataSet([
                'customer_name' => 'Meena Iyer',
                'pan_number' => 'MEENA1234I',
                'mobile_no' => '9811122233',
            ])
            ->setActionData($this->consent)
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $report = CibilReport::query()->sole();

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame(CibilRequestType::Score, $report->request_type);
        $this->assertSame($customer->id, $report->customer_id);
        $this->assertNull($report->lead_id);
        $this->assertSame('MEENA1234I', $report->pan_number);
        $this->assertSame('female', $report->gender);
        $this->assertSame($this->caller->id, $report->requested_by);
    }

    public function test_the_file_pan_wins_over_anything_posted_for_it(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->caller);

        $this->profile($customer, ViewCustomer::class)
            ->callAction(TestAction::make('checkCibilScore')->table(), [...$this->consent, 'pan_number' => 'OTHER1234X'])
            ->assertHasNoActionErrors();

        $this->assertSame('MEENA1234I', CibilReport::query()->sole()->pan_number);
    }

    public function test_full_reports_and_pdfs_need_their_own_permission(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->caller);

        $this->profile($customer, ViewCustomer::class)
            ->assertActionVisible(TestAction::make('checkCibilScore')->table())
            ->assertActionHidden(TestAction::make('fetchCibilReport')->table())
            ->assertActionHidden(TestAction::make('fetchCibilPdf')->table())
            ->assertActionHidden(TestAction::make('refreshCibil')->table());

        // Hiding the button is not the protection: the service refuses too.
        try {
            app(CibilReportService::class)->request($this->caller, CibilRequestType::FullReport, [
                'customer_name' => 'Meena Iyer', 'pan_number' => 'MEENA1234I', 'mobile_no' => '9811122233', ...$this->consent,
            ], $customer);
            $this->fail('Expected the full report to need its permission.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, CibilReport::query()->count());

        CibilAccess::sync(['Caller'], [], CibilAccess::FULL_REPORT_PERMISSION);
        $this->actingAs($this->caller->fresh());

        $this->profile($customer, ViewCustomer::class)
            ->callAction(TestAction::make('fetchCibilReport')->table(), $this->consent)
            ->assertHasNoActionErrors();

        $this->assertSame(CibilRequestType::FullReport, CibilReport::query()->sole()->request_type);
    }

    public function test_an_employee_without_cibil_access_cannot_request_a_report(): void
    {
        CibilAccess::sync([], []);
        $customer = $this->customer();

        $this->actingAs($this->caller->fresh());

        $this->assertFalse(CibilProfileRelationManager::canViewForRecord($customer, ViewCustomer::class));

        $this->profile($customer, ViewCustomer::class)
            ->assertActionHidden(TestAction::make('checkCibilScore')->table());

        try {
            app(CibilReportService::class)->request($this->caller->fresh(), CibilRequestType::Score, [
                'customer_name' => 'Meena Iyer', 'pan_number' => 'MEENA1234I', 'mobile_no' => '9811122233', ...$this->consent,
            ], $customer);
            $this->fail('Expected the request to be refused.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, CibilReport::query()->count());
        $this->assertSame(0, $this->bureau->calls);
    }

    public function test_the_full_report_is_hidden_from_employees_without_the_full_report_permission(): void
    {
        $customer = $this->customer();
        $report = CibilReport::factory()->create([
            'customer_id' => $customer->id,
            'pan_number' => 'MEENA1234I',
            'score' => 744,
            'requested_by' => $this->manager->id,
            'response_payload' => ['credit_report' => [['accounts' => [['memberShortName' => 'CONFIDENTIAL LENDER']]]]],
        ]);

        $this->actingAs($this->caller);

        Livewire::test(ViewCibilReport::class, ['record' => $report->getRouteKey()])
            ->assertOk()
            ->assertSee('744')
            ->assertDontSee('CONFIDENTIAL LENDER');

        CibilAccess::sync(['Caller'], [], CibilAccess::FULL_REPORT_PERMISSION);
        $this->actingAs($this->caller->fresh());

        Livewire::test(ViewCibilReport::class, ['record' => $report->getRouteKey()])
            ->assertSee('CONFIDENTIAL LENDER');
    }

    public function test_missing_details_must_be_entered_before_anything_is_sent(): void
    {
        $customer = $this->customer(['pan_number' => '', 'mobile_no' => '']);

        $this->actingAs($this->caller);

        $this->profile($customer, ViewCustomer::class)
            ->callAction(TestAction::make('checkCibilScore')->table(), [...$this->consent, 'gender' => null])
            ->assertHasActionErrors(['pan_number' => 'required', 'mobile_no' => 'required', 'gender' => 'required']);

        $this->assertSame(0, CibilReport::query()->count());

        // With no PAN on file, the one typed in is used.
        $this->profile($customer, ViewCustomer::class)
            ->callAction(TestAction::make('checkCibilScore')->table(), [...$this->consent, 'pan_number' => 'TYPED1234P', 'mobile_no' => '9000011111'])
            ->assertHasNoActionErrors();

        $this->assertSame('TYPED1234P', CibilReport::query()->sole()->pan_number);
    }

    public function test_consent_is_enforced_on_the_customer_page(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->caller);

        $this->profile($customer, ViewCustomer::class)
            ->callAction(TestAction::make('checkCibilScore')->table(), ['gender' => 'female', 'consent' => false])
            ->assertHasActionErrors(['consent' => 'accepted', 'consent_method' => 'required']);

        $this->assertSame(0, CibilReport::query()->count());
    }

    public function test_an_existing_report_on_the_file_is_reused(): void
    {
        $customer = $this->customer();
        $existing = CibilReport::factory()->score()->create(['customer_id' => $customer->id, 'pan_number' => 'MEENA1234I', 'requested_by' => $this->caller->id]);

        $this->actingAs($this->caller);

        $this->profile($customer, ViewCustomer::class)
            ->callAction(TestAction::make('checkCibilScore')->table(), $this->consent)
            ->assertNotified('Existing report used');

        $this->assertSame([$existing->id], CibilReport::query()->pluck('id')->all());
        $this->assertSame(0, $this->bureau->calls);
    }

    public function test_a_duplicate_request_in_flight_is_refused(): void
    {
        $customer = $this->customer();
        CibilReport::factory()->score()->pending()->create(['customer_id' => $customer->id, 'pan_number' => 'MEENA1234I', 'created_at' => now()->subMinute()]);

        $this->actingAs($this->caller);

        $this->profile($customer, ViewCustomer::class)
            ->callAction(TestAction::make('checkCibilScore')->table(), $this->consent)
            ->assertNotified('CIBIL request not sent');

        $this->assertSame(1, CibilReport::query()->count());
        $this->assertSame(0, $this->bureau->calls);
    }

    public function test_a_manager_sees_the_report_a_caller_fetched_on_their_teams_file(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->caller);
        $report = app(CibilReportService::class)->request($this->caller, CibilRequestType::Score, [
            'customer_name' => 'Meena Iyer', 'pan_number' => 'MEENA1234I', 'mobile_no' => '9811122233', ...$this->consent,
        ], $customer)->report;

        $this->actingAs($this->manager);
        $this->assertTrue(app(CibilReportService::class)->canView($report, $this->manager));

        // …and reuses it instead of paying again.
        $this->profile($customer, ViewCustomer::class)
            ->assertCanSeeTableRecords([$report])
            ->callAction(TestAction::make('checkCibilScore')->table(), $this->consent)
            ->assertNotified('Existing report used');

        $this->assertSame(1, $this->bureau->calls);

        // Someone outside the branch neither sees the file nor its report.
        $this->actingAs($this->outsider);
        $this->assertFalse(app(CibilReportService::class)->canView($report, $this->outsider));
    }

    // ---- My Leads lead -----------------------------------------------------

    public function test_an_authorised_employee_requests_a_report_from_the_lead_page_with_its_details(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->caller);
        $this->assertTrue(CibilProfileRelationManager::canViewForRecord($lead, EditLead::class));
        $this->assertContains(CibilProfileRelationManager::class, LeadResource::getRelations());

        $this->profile($lead, EditLead::class)
            ->mountAction(TestAction::make('checkCibilScore')->table())
            ->assertActionDataSet(['customer_name' => 'Nisha Sharma', 'pan_number' => 'BBBBB1212J', 'mobile_no' => '8800618269'])
            ->setActionData($this->consent)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $report = CibilReport::query()->sole();
        $this->assertSame($lead->id, $report->lead_id);
        $this->assertNull($report->customer_id);
        $this->assertSame('BBBBB1212J', $report->pan_number);
    }

    public function test_missing_lead_details_and_consent_are_enforced(): void
    {
        $lead = $this->lead(['pan_number' => null]);

        $this->actingAs($this->caller);

        $this->profile($lead, EditLead::class)
            ->callAction(TestAction::make('checkCibilScore')->table(), ['gender' => 'male', 'consent' => false])
            ->assertHasActionErrors(['pan_number' => 'required', 'consent' => 'accepted', 'consent_method' => 'required']);

        $this->assertSame(0, CibilReport::query()->count());
        $this->assertSame(0, $this->bureau->calls);
    }

    public function test_a_lead_the_employee_cannot_see_is_refused(): void
    {
        $someoneElses = $this->lead(['employee_id' => $this->otherEmployee->id]);

        $this->actingAs($this->caller);

        try {
            $this->profile($someoneElses, EditLead::class)
                ->callAction(TestAction::make('checkCibilScore')->table(), $this->consent);
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame(0, CibilReport::query()->count());
        $this->assertSame(0, $this->bureau->calls);
    }

    public function test_each_leads_report_history_stays_with_that_lead(): void
    {
        $first = $this->lead();
        $second = $this->lead(['customer_name' => 'Ravi Kumar', 'pan_number' => 'RAVIK1234K', 'mobile_no' => '9876501234']);
        $firstReport = CibilReport::factory()->score()->create(['lead_id' => $first->id, 'pan_number' => 'BBBBB1212J']);
        $secondReport = CibilReport::factory()->score()->create(['lead_id' => $second->id, 'pan_number' => 'RAVIK1234K']);

        $this->actingAs($this->caller);

        $this->profile($first, EditLead::class)
            ->assertCanSeeTableRecords([$firstReport])
            ->assertCanNotSeeTableRecords([$secondReport]);
    }

    public function test_after_conversion_the_customer_profile_shows_the_leads_report_once(): void
    {
        $lead = $this->lead(['status' => 'Eligible']);
        $leadReport = CibilReport::factory()->score()->create(['lead_id' => $lead->id, 'pan_number' => 'BBBBB1212J', 'requested_by' => $this->caller->id]);
        $customer = $this->customer(['customer_name' => 'Nisha Sharma', 'pan_number' => 'BBBBB1212J', 'mobile_no' => '8800618269']);
        $lead->update(['is_converted' => true, 'converted_customer_id' => $customer->id]);

        app(CibilReportService::class)->linkConvertedLead($customer, $lead->id);

        $this->actingAs($this->caller);

        $this->profile($customer, ViewCustomer::class)
            ->assertCanSeeTableRecords([$leadReport])
            ->assertCountTableRecords(1);

        $this->assertSame(1, CibilReport::query()->count());
    }

    // ---- Assigned lead -----------------------------------------------------

    public function test_an_assigned_lead_gets_the_profile_on_its_view_page(): void
    {
        $template = AiDocumentSchema::create(['name' => 'Axis Template Data', 'is_active' => true, 'fields' => []]);
        $record = AiCustomerRecord::create([
            'schema_id' => $template->id,
            'status' => 'approved',
            'data' => ['customer_name' => 'Sunil Verma', 'mobile_number' => '9123456780', 'pan_number' => 'lmnop4321q'],
        ]);
        app(CustomerAssignmentService::class)->assign(collect([$record->id]), $this->callerEmployee->id, CustomerAssignmentService::TARGET_AI_RECORD, null);
        $assigned = CustomerAssignment::query()->where('ai_customer_record_id', $record->id)->sole();

        $this->actingAs($this->caller);

        $this->profile($assigned, ViewAssignedLead::class)
            ->mountAction(TestAction::make('checkCibilScore')->table())
            ->assertActionDataSet(['customer_name' => 'Sunil Verma', 'pan_number' => 'LMNOP4321Q', 'mobile_no' => '9123456780'])
            ->setActionData(['gender' => 'male', 'consent_method' => 'digital', 'consent' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($assigned->id, CibilReport::query()->sole()->customer_assignment_id);
    }
}
