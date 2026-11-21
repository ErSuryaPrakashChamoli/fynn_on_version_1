<?php

namespace Tests\Feature;

use App\Filament\Resources\AssignedLeads\Pages\EditAssignedLead;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Services\CustomerAssignmentService;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fields that tidy their own value on the server (Title Case names,
 * UPPER CASE PAN, Indian comma grouping on amounts) must only reach the
 * server once the box is left. Sent on every keystroke, the tidied value
 * came back and was written over the letters typed while the request was
 * in flight, so half a name or a loan figure went missing.
 */
class TypedFieldsKeepEveryKeystrokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    private Employee $callerEmployee;

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

        City::forceCreate(['country' => 'India', 'state' => 'Karnataka', 'city' => 'Bangalore', 'is_active' => 1]);
    }

    public function test_the_lead_form_sends_name_pan_and_salary_only_when_the_box_is_left(): void
    {
        $this->actingAs($this->caller);

        $page = Livewire::test(CreateLead::class);

        foreach (['customer_name', 'pan_number', 'salary'] as $field) {
            $page->assertSeeHtml('wire:model.live.blur="data.'.$field.'"')
                ->assertDontSeeHtml('wire:model.live="data.'.$field.'"');
        }

        $this->assertFieldsUpdateOnBlur($page->instance()->form, ['customer_name', 'pan_number', 'salary']);
    }

    public function test_the_lead_form_still_tidies_the_values_and_saves_them(): void
    {
        $this->actingAs($this->caller);

        Livewire::test(CreateLead::class)
            ->fillForm([
                'customer_name' => 'rahul kumar sharma',
                'mobile_no' => '9876543210',
                'pan_number' => 'abcde1234f',
                'salary' => '1250000',
                'job_location' => 'Bangalore',
                'follow_up_type' => 'Call',
                'status' => 'Not Interested',
                'remarks' => 'First call',
            ])
            ->assertFormSet([
                'customer_name' => 'Rahul Kumar Sharma',
                'pan_number' => 'ABCDE1234F',
                'salary' => '12,50,000',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('leads', [
            'customer_name' => 'Rahul Kumar Sharma',
            'pan_number' => 'ABCDE1234F',
            'salary' => 1250000,
        ]);
    }

    public function test_the_lead_form_shows_capitals_while_the_name_and_pan_are_typed(): void
    {
        $this->actingAs($this->caller);

        $form = Livewire::test(CreateLead::class)->instance()->form;

        $this->assertSame('text-transform: capitalize', $this->inputStyle($form, 'customer_name'));
        $this->assertSame('text-transform: uppercase', $this->inputStyle($form, 'pan_number'));
    }

    public function test_the_assigned_lead_salary_is_sent_only_when_the_box_is_left(): void
    {
        $template = AiDocumentSchema::create([
            'name' => 'Upload',
            'is_active' => true,
            'fields' => [
                ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text'],
                ['key' => 'mobile', 'label' => 'Mobile', 'type' => 'mobile'],
            ],
        ]);
        $record = AiCustomerRecord::create([
            'schema_id' => $template->id,
            'status' => 'review',
            'confidence_score' => 1,
            'data' => ['customer_name' => 'Uploaded Person', 'mobile' => '9789060489'],
        ]);
        app(CustomerAssignmentService::class)->assign(collect([$record->id]), $this->callerEmployee->id, CustomerAssignmentService::TARGET_AI_RECORD, null);
        $assignment = CustomerAssignment::query()->where('ai_customer_record_id', $record->id)->firstOrFail();

        $this->actingAs($this->admin);

        $page = Livewire::test(EditAssignedLead::class, ['record' => $assignment->getRouteKey()])
            ->assertSeeHtml('wire:model.live.blur="data.salary"')
            ->assertDontSeeHtml('wire:model.live="data.salary"');

        $this->assertFieldsUpdateOnBlur($page->instance()->form, ['salary']);
    }

    public function test_the_customer_journey_names_and_amounts_are_sent_only_when_the_box_is_left(): void
    {
        $customer = Customer::factory()->create([
            'assign_to' => $this->callerEmployee->id,
            'employee_id' => $this->callerEmployee->id,
            'eligibility_status' => 'eligible',
            'bank_eligible_for' => 'ABFL',
            'journey_status' => 'underwriting',
            'underwriting_status' => 'approved',
            'salary' => 85000,
        ]);

        $this->actingAs($this->adminWithoutEmployeeRecord());

        $form = Livewire::test(EditCustomer::class, ['record' => $customer->id])->instance()->form;

        $this->assertFieldsUpdateOnBlur($form, [
            'customer_name',
            'salary',
            'company_category',
            'eligible_loan_amount',
            'approved_loan_amount',
            'other_sanctioned_bank',
            'sanctioned_loan_amount',
            'subvention',
            'docking',
        ]);

        foreach (['customer_name', 'company_category', 'other_sanctioned_bank'] as $field) {
            $this->assertSame('text-transform: capitalize', $this->inputStyle($form, $field), $field);
        }
    }

    public function test_the_customer_journey_amount_is_still_grouped_once_entered(): void
    {
        $customer = Customer::factory()->create([
            'assign_to' => $this->callerEmployee->id,
            'employee_id' => $this->callerEmployee->id,
            'eligibility_status' => 'eligible',
            'bank_eligible_for' => 'ABFL',
            'journey_status' => 'underwriting',
            'underwriting_status' => 'approved',
            'approved_loan_amount' => 1250000,
        ]);

        $this->actingAs($this->adminWithoutEmployeeRecord());

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->fillForm(['approved_loan_amount' => '1500000'])
            ->assertFormSet(['approved_loan_amount' => '15,00,000']);
    }

    /**
     * As on the live data: the Admin login has no employee record.
     */
    private function adminWithoutEmployeeRecord(): User
    {
        $admin = User::factory()->create(['employee_id' => null]);
        $admin->assignRole('Admin');

        return $admin;
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function assertFieldsUpdateOnBlur(Schema $form, array $fields): void
    {
        foreach ($fields as $field) {
            $this->assertTrue($this->textInput($form, $field)->isLiveOnBlur(), "{$field} must update on blur, not on every keystroke.");
        }
    }

    private function inputStyle(Schema $form, string $field): ?string
    {
        return $this->textInput($form, $field)->getExtraInputAttributes()['style'] ?? null;
    }

    /**
     * The typed box itself — a read-only Placeholder may share its name.
     */
    private function textInput(Schema $schema, string $field): TextInput
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof TextInput && $component->getName() === $field) {
                return $component;
            }

            foreach ($component->getChildSchemas(withHidden: true) as $childSchema) {
                try {
                    return $this->textInput($childSchema, $field);
                } catch (\OutOfBoundsException) {
                    continue;
                }
            }
        }

        throw new \OutOfBoundsException("No text input named [{$field}].");
    }
}
