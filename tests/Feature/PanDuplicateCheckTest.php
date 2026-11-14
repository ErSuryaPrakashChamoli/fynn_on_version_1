<?php

namespace Tests\Feature;

use App\Filament\Resources\AssignedLeads\Pages\EditAssignedLead;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\Bank;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerPanRequest;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Rules\PanIsAvailable;
use App\Services\CustomerAssignmentService;
use App\Services\CustomerEditRequestService;
use App\Services\PanDuplicateService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A PAN is checked against BOTH the customers and the open leads wherever
 * it is entered or changed. A clash is refused until an Admin approves a
 * duplicate PAN request, which is spent when the record is saved.
 */
class PanDuplicateCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    private Employee $callerEmployee;

    private Employee $otherEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Caller', 'guard_name' => 'web']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create(['designation' => Employee::DESIGNATION_ADMIN])->id]);
        $this->admin->assignRole('Admin');

        $this->callerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->caller = User::factory()->create(['employee_id' => $this->callerEmployee->id]);
        $this->caller->assignRole('Caller');

        $this->otherEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no', 'emp_name' => 'Other Owner']);

        City::forceCreate(['country' => 'India', 'state' => 'Karnataka', 'city' => 'Bangalore', 'is_active' => 1]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(array $attributes = []): Lead
    {
        return Lead::create([
            'employee_id' => $this->otherEmployee->id,
            'customer_name' => 'Existing Lead',
            'mobile_no' => '9811122233',
            'pan_number' => 'LEADP1234L',
            'job_location' => 'Bangalore',
            'follow_up_type' => 'Call',
            'status' => 'Interested',
            'remarks' => 'Already being worked',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function leadForm(array $attributes = []): array
    {
        return [
            'customer_name' => 'New Prospect',
            'mobile_no' => '9876543210',
            'job_location' => 'Bangalore',
            'follow_up_type' => 'Call',
            'status' => 'Not Interested',
            'remarks' => 'First call',
            ...$attributes,
        ];
    }

    private function approve(CustomerPanRequest $request): void
    {
        $request->update(['status' => CustomerPanRequest::STATUS_APPROVED, 'approved_by' => $this->admin->employee_id, 'approved_at' => now()]);
    }

    // ---------------------------------------------------------------- service

    public function test_the_check_covers_customers_and_open_leads_but_not_converted_leads(): void
    {
        $customer = Customer::factory()->create(['pan_number' => 'SHARE1234S']);
        $openLead = $this->lead(['pan_number' => 'SHARE1234S']);
        $this->lead(['pan_number' => 'SHARE1234S', 'is_converted' => true, 'converted_customer_id' => $customer->id]);

        $matches = app(PanDuplicateService::class)->matches(' share1234s ');

        $this->assertSame(
            [['customer', $customer->id], ['lead', $openLead->id]],
            $matches->map(fn ($match): array => [$match->type, $match->id])->all(),
        );

        $this->assertTrue(app(PanDuplicateService::class)->matches('SHARE1234S', ignoreCustomerId: $customer->id, ignoreLeadId: $openLead->id)->isEmpty());
        $this->assertNull(app(PanDuplicateService::class)->firstMatch('FREEP1234F'));
    }

    public function test_the_rule_names_the_clashing_record(): void
    {
        $this->lead(['pan_number' => 'LEADP1234L']);

        $validator = Validator::make(['pan' => 'LEADP1234L'], ['pan' => [new PanIsAvailable(mentionApproval: false)]]);

        $this->assertTrue($validator->fails());
        $this->assertSame('This PAN is already used by lead Existing Lead (owner: Other Owner).', $validator->errors()->first('pan'));
        $this->assertFalse(Validator::make(['pan' => 'FREEP1234F'], ['pan' => [new PanIsAvailable]])->fails());
    }

    // ---------------------------------------------------------------- lead form

    public function test_a_new_lead_with_a_customers_pan_is_refused(): void
    {
        Customer::factory()->create(['pan_number' => 'CUSTP1234C', 'customer_name' => 'Rahul Sharma']);
        $this->actingAs($this->caller);

        Livewire::test(CreateLead::class)
            ->fillForm($this->leadForm(['pan_number' => 'custp1234c']))
            ->assertFormSet(['pan_number' => 'CUSTP1234C', 'pan_status' => 'exists'])
            ->assertSee('Duplicate PAN Found')
            ->assertSee('Rahul Sharma')
            ->call('create')
            ->assertHasFormErrors(['pan_number']);

        $this->assertDatabaseMissing('leads', ['customer_name' => 'New Prospect']);
    }

    public function test_a_new_lead_with_another_open_leads_pan_is_refused(): void
    {
        $this->lead(['pan_number' => 'LEADP1234L']);
        $this->actingAs($this->caller);

        Livewire::test(CreateLead::class)
            ->fillForm($this->leadForm(['pan_number' => 'LEADP1234L']))
            ->assertFormSet(['pan_status' => 'exists'])
            ->call('create')
            ->assertHasFormErrors(['pan_number']);

        $this->assertDatabaseMissing('leads', ['customer_name' => 'New Prospect']);
    }

    public function test_a_new_lead_with_an_unused_pan_is_saved(): void
    {
        $this->actingAs($this->caller);

        Livewire::test(CreateLead::class)
            ->fillForm($this->leadForm(['pan_number' => 'FREEP1234F']))
            ->assertFormSet(['pan_status' => 'available'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('leads', ['customer_name' => 'New Prospect', 'pan_number' => 'FREEP1234F']);
    }

    public function test_a_lead_clash_can_be_approved_by_the_admin_and_then_saved(): void
    {
        $existing = $this->lead(['pan_number' => 'LEADP1234L']);
        $this->actingAs($this->caller);

        Livewire::test(CreateLead::class)
            ->fillForm($this->leadForm(['pan_number' => 'LEADP1234L']))
            ->callAction(TestAction::make('requestPanApproval')->schemaComponent('leadPanConflictSection'), ['reason' => 'Customer called us directly.'])
            ->assertHasNoFormErrors()
            ->assertFormSet(['pan_status' => 'pending'])
            ->call('create')
            ->assertHasFormErrors(['pan_number']);

        $request = CustomerPanRequest::query()->sole();
        $this->assertSame(CustomerPanRequest::FOR_LEAD, $request->request_for);
        $this->assertSame($existing->id, $request->matched_lead_id);
        $this->assertNull($request->customer_id);
        $this->assertNull($request->lead_id);
        $this->assertSame('LEADP1234L', $request->pan_number);
        $this->assertSame($this->callerEmployee->id, $request->requested_by);
        $this->assertSame('New Duplicate PAN Request', $this->admin->fresh()->notifications->first()?->data['title']);

        $this->approve($request);

        Livewire::withQueryParams(['pan_request' => $request->id]);

        Livewire::test(CreateLead::class)
            ->assertFormSet(['pan_number' => 'LEADP1234L', 'pan_status' => 'approved', 'customer_name' => 'Existing Lead'])
            ->fillForm($this->leadForm(['pan_number' => 'LEADP1234L']))
            ->call('create')
            ->assertHasNoFormErrors();

        $saved = Lead::query()->where('customer_name', 'New Prospect')->sole();
        $request->refresh();
        $this->assertSame($saved->id, $request->lead_id);
        $this->assertNotNull($request->used_at);
        $this->assertTrue($request->isUsed());

        // A spent approval does not cover a second lead.
        Livewire::withQueryParams([]);
        Livewire::test(CreateLead::class)
            ->fillForm($this->leadForm(['customer_name' => 'Third Try', 'pan_number' => 'LEADP1234L']))
            ->assertFormSet(['pan_status' => 'exists'])
            ->call('create')
            ->assertHasFormErrors(['pan_number']);
    }

    public function test_editing_a_lead_without_touching_its_pan_is_never_blocked(): void
    {
        // Older data: a lead that already shares its PAN with a customer.
        Customer::factory()->create(['pan_number' => 'OLDDP1234D']);
        $lead = $this->lead(['employee_id' => $this->callerEmployee->id, 'pan_number' => 'OLDDP1234D']);
        $this->actingAs($this->caller);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm(['remarks' => 'Spoke again', 'status' => 'Not Interested'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Spoke again', $lead->fresh()->remarks);
    }

    public function test_changing_a_leads_pan_to_a_customers_pan_is_refused_until_approved(): void
    {
        Customer::factory()->create(['pan_number' => 'CUSTP1234C']);
        $lead = $this->lead(['employee_id' => $this->callerEmployee->id, 'pan_number' => 'MINEP1234M']);
        $this->actingAs($this->caller);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm(['pan_number' => 'CUSTP1234C', 'status' => 'Not Interested'])
            ->call('save')
            ->assertHasFormErrors(['pan_number'])
            ->callAction(TestAction::make('requestPanApproval')->schemaComponent('leadPanConflictSection'), ['reason' => 'Typo on the old PAN.']);

        $this->assertSame('MINEP1234M', $lead->fresh()->pan_number);

        $request = CustomerPanRequest::query()->sole();
        $this->assertSame($lead->id, $request->lead_id);
        $this->approve($request);

        Livewire::withQueryParams(['pan_request' => $request->id]);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertFormSet(['pan_number' => 'CUSTP1234C', 'pan_status' => 'approved'])
            ->fillForm(['status' => 'Not Interested'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('CUSTP1234C', $lead->fresh()->pan_number);
        $this->assertNotNull($request->fresh()->used_at);
    }

    // ---------------------------------------------------------------- customer form

    public function test_a_new_customer_with_an_open_leads_pan_is_held_for_approval(): void
    {
        $existing = $this->lead(['pan_number' => 'LEADP1234L']);
        $bank = Bank::create(['bank_name' => 'Test Bank', 'loan_type' => 'personal_loan']);
        $this->actingAs($this->caller);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['pan_number' => 'LEADP1234L'])
            ->assertFormSet(['existing_lead_id' => $existing->id, 'pan_status' => 'exists_lead'])
            ->assertSet('existingLead.id', $existing->id)
            ->assertSee('Existing Lead (Duplicate PAN)')
            ->call('create')
            ->assertHasFormErrors(['pan_number'])
            ->callAction(TestAction::make('requestApproval')->schemaComponent('existingCustomerSection'), [
                'requested_bank_id' => $bank->id,
                'requested_loan_type' => 'personal_loan',
                'reason' => 'Walked in at the branch.',
            ]);

        $this->assertDatabaseCount('customers', 0);

        $request = CustomerPanRequest::query()->sole();
        $this->assertNull($request->customer_id);
        $this->assertSame($existing->id, $request->matched_lead_id);
        $this->assertSame(CustomerPanRequest::FOR_CUSTOMER, $request->request_for);
        $this->assertSame('Test Bank', $request->requested_bank_name);

        $this->approve($request);

        Livewire::withQueryParams(['pan_request' => $request->id]);

        Livewire::test(CreateCustomer::class)
            ->assertSet('isApprovedPanRequest', true)
            ->assertFormSet([
                'pan_number' => 'LEADP1234L',
                'existing_lead_id' => $existing->id,
                'customer_name' => 'Existing Lead',
                'loan_applied' => 'personal_loan',
            ])
            ->assertDontSee('Existing Lead (Duplicate PAN)');
    }

    public function test_converting_a_lead_does_not_clash_with_the_lead_itself_but_does_with_a_customer(): void
    {
        $lead = $this->lead(['employee_id' => $this->callerEmployee->id, 'pan_number' => 'CONVP1234C', 'status' => 'Eligible']);
        $this->actingAs($this->caller);

        Livewire::withQueryParams(['lead' => $lead->id]);

        Livewire::test(CreateCustomer::class)
            ->assertFormSet(['pan_number' => 'CONVP1234C', 'pan_status' => 'available', 'existing_lead_id' => null])
            ->assertSet('existingLead', null);

        $customer = Customer::factory()->create(['pan_number' => 'CONVP1234C']);

        Livewire::test(CreateCustomer::class)
            ->assertFormSet(['existing_customer_id' => $customer->id, 'pan_status' => 'exists']);
    }

    public function test_converting_a_lead_whose_pan_another_open_lead_carries_is_held(): void
    {
        $other = $this->lead(['pan_number' => 'TWINP1234T', 'customer_name' => 'Twin Lead']);
        $lead = $this->lead(['employee_id' => $this->callerEmployee->id, 'pan_number' => 'TWINP1234T', 'status' => 'Eligible']);
        $this->actingAs($this->caller);

        Livewire::withQueryParams(['lead' => $lead->id]);

        Livewire::test(CreateCustomer::class)
            ->assertFormSet(['existing_lead_id' => $other->id, 'pan_status' => 'exists_lead'])
            ->assertSee('Twin Lead');
    }

    // ---------------------------------------------------------------- assigned leads

    public function test_an_assigned_lead_warns_when_its_pan_is_already_in_use(): void
    {
        $this->lead(['pan_number' => 'LEADP1234L']);
        $template = AiDocumentSchema::create([
            'name' => 'Upload',
            'is_active' => true,
            'fields' => [
                ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text'],
                ['key' => 'mobile', 'label' => 'Mobile', 'type' => 'mobile'],
                ['key' => 'pan', 'label' => 'PAN', 'type' => 'pan'],
            ],
        ]);
        $record = AiCustomerRecord::create([
            'schema_id' => $template->id,
            'status' => 'review',
            'confidence_score' => 1,
            'data' => ['customer_name' => 'Uploaded Person', 'mobile' => '9789060489', 'pan' => 'leadp1234l'],
        ]);
        app(CustomerAssignmentService::class)->assign(collect([$record->id]), $this->callerEmployee->id, CustomerAssignmentService::TARGET_AI_RECORD, null);
        $assignment = CustomerAssignment::query()->where('ai_customer_record_id', $record->id)->firstOrFail();

        $this->actingAs($this->admin);

        Livewire::test(EditAssignedLead::class, ['record' => $assignment->getRouteKey()])
            ->assertSee('This PAN is already used by lead Existing Lead (owner: Other Owner). Converting this lead will need Admin approval.');
    }

    // ---------------------------------------------------------------- edit requests

    public function test_a_customer_edit_request_cannot_move_a_file_onto_a_pan_in_use(): void
    {
        $this->lead(['pan_number' => 'LEADP1234L']);
        $customer = Customer::factory()->create([
            'pan_number' => 'MINEP1234M',
            'employee_id' => $this->callerEmployee->id,
            'assign_to' => $this->callerEmployee->id,
        ]);

        try {
            app(CustomerEditRequestService::class)->raise($this->admin, $customer, 'basic_details', 'PAN typo', [
                ['field' => 'pan_number', 'value' => 'leadp1234l'],
            ]);
            $this->fail('A PAN already on an open lead was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This PAN is already used by lead Existing Lead (owner: Other Owner).',
                $exception->validator->errors()->first('changes.0.value'),
            );
        }

        $this->assertSame('MINEP1234M', $customer->fresh()->pan_number);
    }
}
