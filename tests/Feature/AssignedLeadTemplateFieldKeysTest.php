<?php

namespace Tests\Feature;

use App\Filament\Resources\AssignedLeads\Pages\EditAssignedLead;
use App\Filament\Resources\AssignedLeads\Pages\ViewAssignedLead;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\City;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\CustomerAssignmentService;
use App\Support\AiRecordProspectFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Upload templates choose their own field keys ("mobile", "pan", "city"),
 * so the Assigned Lead pages must find prospect details by key synonym,
 * field type or label rather than only the canonical "mobile_number" etc.
 */
class AssignedLeadTemplateFieldKeysTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    private AiDocumentSchema $droppedCasesTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $admin = Employee::factory()->create(['designation' => Employee::DESIGNATION_ADMIN]);
        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        $user = User::factory()->create(['employee_id' => $admin->id]);
        $user->assignRole('Admin');
        $this->actingAs($user);

        City::forceCreate(['country' => 'India', 'state' => 'Karnataka', 'city' => 'Bangalore', 'is_active' => 1]);
        City::forceCreate(['country' => 'India', 'state' => 'Tamil Nadu', 'city' => 'Chennai', 'is_active' => 1]);

        $this->droppedCasesTemplate = AiDocumentSchema::create([
            'name' => 'DROPPED Cases Lot 1st',
            'is_active' => true,
            'fields' => [
                ['key' => 'dob', 'label' => 'Date of Birth', 'type' => 'date'],
                ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text'],
                ['key' => 'mobile', 'label' => 'Mobile', 'type' => 'mobile'],
                ['key' => 'pan', 'label' => 'PAN', 'type' => 'pan'],
                ['key' => 'city', 'label' => 'City', 'type' => 'text'],
                ['key' => 'net_salary', 'label' => 'Net Salary', 'type' => 'decimal'],
                ['key' => 'product', 'label' => 'Product', 'type' => 'text'],
            ],
        ]);
    }

    private function assignedLead(AiDocumentSchema $template, array $data): CustomerAssignment
    {
        $record = AiCustomerRecord::create([
            'schema_id' => $template->id,
            'status' => 'review',
            'confidence_score' => 1,
            'data' => $data,
        ]);

        app(CustomerAssignmentService::class)->assign(collect([$record->id]), $this->caller->id, CustomerAssignmentService::TARGET_AI_RECORD, null);

        return CustomerAssignment::where('ai_customer_record_id', $record->id)->firstOrFail();
    }

    private function droppedCaseLead(): CustomerAssignment
    {
        return $this->assignedLead($this->droppedCasesTemplate, [
            'dob' => '1997-08-22',
            'customer_name' => 'ASHOK CHAKRAVARTHY',
            'mobile' => '9789060489',
            'pan' => 'epapp0500r',
            'city' => 'BANGALORE',
            'net_salary' => '4000000',
            'product' => 'OD',
        ]);
    }

    public function test_edit_page_prefills_details_stored_under_the_templates_own_keys(): void
    {
        $lead = $this->droppedCaseLead();

        Livewire::test(EditAssignedLead::class, ['record' => $lead->getRouteKey()])
            ->assertFormSet([
                'customer_name' => 'ASHOK CHAKRAVARTHY',
                'mobile_no' => '9789060489',
                'pan_number' => 'EPAPP0500R',
                'current_location' => 'Bangalore',
                'salary' => '40,00,000',
            ]);
    }

    public function test_fields_resolve_by_template_type_and_by_label_or_alias(): void
    {
        $template = AiDocumentSchema::create([
            'name' => 'Bank Export',
            'is_active' => true,
            'fields' => [
                ['key' => 'applicant', 'label' => 'Applicant', 'type' => 'text'],
                ['key' => 'cust_contact', 'label' => 'Reachable On', 'type' => 'mobile'],
                ['key' => 'col_tax_id', 'label' => 'Tax Id', 'aliases' => ['Permanent Account, PAN Card No'], 'type' => 'text'],
                ['key' => 'office_at', 'label' => 'Office Location', 'type' => 'text'],
            ],
        ]);

        $lead = $this->assignedLead($template, [
            'applicant' => 'Nimisha P',
            'cust_contact' => '+91 98765 43210',
            'col_tax_id' => 'ABCDE1234F',
            'office_at' => 'chennai',
        ]);

        Livewire::test(EditAssignedLead::class, ['record' => $lead->getRouteKey()])
            ->assertFormSet([
                'customer_name' => 'Nimisha P',
                'mobile_no' => '9876543210',
                'pan_number' => 'ABCDE1234F',
                'job_location' => 'Chennai',
            ]);
    }

    public function test_a_location_that_is_not_an_active_city_is_left_blank(): void
    {
        $lead = $this->assignedLead($this->droppedCasesTemplate, [
            'customer_name' => 'Uday Kumar Giri',
            'city' => 'Atlantis',
        ]);

        Livewire::test(EditAssignedLead::class, ['record' => $lead->getRouteKey()])
            ->assertFormSet(['current_location' => null]);
    }

    public function test_canonical_keys_still_win(): void
    {
        $lead = $this->assignedLead($this->droppedCasesTemplate, [
            'customer_name' => 'Deepika Dogra',
            'mobile' => '9000000000',
            'mobile_number' => '9876543210',
        ]);

        $this->assertSame('9876543210', $lead->aiCustomerRecord->prospectValue('mobile_number'));
    }

    public function test_saving_writes_edits_back_under_the_templates_keys(): void
    {
        $lead = $this->droppedCaseLead();

        Livewire::test(EditAssignedLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm([
                'pan_number' => 'BBBBB2222B',
                'email' => 'ashok@example.com',
                'current_location' => 'Chennai',
                'follow_up_type' => 'Call',
                'status' => 'Interested',
                'next_follow_up_date' => now()->addDay()->format('Y-m-d H:i'),
                'remarks' => 'Spoke to the customer.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $data = $lead->aiCustomerRecord->fresh()->data;

        $this->assertSame('BBBBB2222B', $data['pan']);
        $this->assertSame('Chennai', $data['city']);
        $this->assertSame('ashok@example.com', $data['email']);
        $this->assertSame('9789060489', $data['mobile']);
        $this->assertArrayNotHasKey('pan_number', $data);
        $this->assertArrayNotHasKey('current_location', $data);

        $this->assertDatabaseHas(FollowUp::class, [
            'ai_customer_record_id' => $lead->ai_customer_record_id,
            'status' => 'Interested',
        ]);
    }

    public function test_write_back_key_never_overwrites_a_field_matched_only_by_alias(): void
    {
        $template = AiDocumentSchema::create([
            'name' => 'Axis Template Data',
            'is_active' => true,
            'fields' => [
                ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text'],
                ['key' => 'state', 'label' => 'State', 'aliases' => ['State, Location, Region'], 'type' => 'text'],
            ],
        ]);

        $lead = $this->assignedLead($template, ['customer_name' => 'Deepika Dogra', 'state' => 'haryana']);

        $this->assertNull($lead->aiCustomerRecord->prospectValue('current_location'));
        $this->assertSame('current_location', AiRecordProspectFields::key($lead->aiCustomerRecord, 'current_location'));
    }

    public function test_view_page_lists_each_extracted_field_with_its_label(): void
    {
        $lead = $this->droppedCaseLead();

        Livewire::test(ViewAssignedLead::class, ['record' => $lead->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeInOrder(['Date of Birth', '22 Aug 1997', 'Customer Name', 'ASHOK CHAKRAVARTHY', 'Mobile', '9789060489', 'PAN', 'epapp0500r', 'City', 'BANGALORE', 'Net Salary', '40,00,000', 'Product', 'OD'])
            ->assertSee('Review')
            ->assertSee('100.0%')
            ->assertDontSee('0: 9789060489');
    }

    public function test_view_page_also_lists_details_added_outside_the_template(): void
    {
        $lead = $this->assignedLead($this->droppedCasesTemplate, [
            'customer_name' => 'ASHOK CHAKRAVARTHY',
            'email' => 'ashok@example.com',
        ]);

        Livewire::test(ViewAssignedLead::class, ['record' => $lead->getRouteKey()])
            ->assertSeeInOrder(['Email Address', 'ashok@example.com']);
    }

    public function test_convert_to_customer_prefills_details_stored_under_the_templates_own_keys(): void
    {
        $lead = $this->droppedCaseLead();

        Livewire::withQueryParams(['ai_customer_record' => $lead->ai_customer_record_id]);

        Livewire::test(CreateCustomer::class)
            ->assertSet('aiCustomerRecordId', $lead->ai_customer_record_id)
            ->assertFormSet([
                'customer_name' => 'ASHOK CHAKRAVARTHY',
                'mobile_no' => '9789060489',
                'pan_number' => 'EPAPP0500R',
                'current_location' => 'Bangalore',
            ]);
    }
}
