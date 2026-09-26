<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUps\Pages\CreateFollowUp;
use App\Filament\Resources\FollowUps\Pages\EditFollowUp;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Customer Details section is read-only and only had create-time
 * defaults, so editing a follow-up showed every detail blank.
 */
class FollowUpEditPageTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        $user = User::factory()->create(['employee_id' => $this->employee->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Admin']));

        $this->actingAs($user);
    }

    public function test_edit_page_shows_the_customers_details(): void
    {
        $customer = Customer::factory()->create([
            'employee_id' => $this->employee->id,
            'customer_name' => 'Rahul Chauhan',
            'mobile_no' => '9876543210',
            'email' => 'rahul@example.com',
            'pan_number' => 'ABCDE1234F',
            'current_location' => 'Delhi',
            'job_location' => 'Noida',
            'salary' => 85000,
        ]);

        $followUp = $this->followUp(['customer_id' => $customer->id]);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->assertSchemaStateSet([
                'customer_name' => 'Rahul Chauhan',
                'mobile_no' => '9876543210',
                'email' => 'rahul@example.com',
                'pan_number' => 'ABCDE1234F',
                'current_location' => 'Delhi',
                'job_location' => 'Noida',
                'salary' => '₹85,000',
                'status' => 'Delay Multifunding',
            ]);
    }

    public function test_edit_page_shows_ai_record_details_for_an_unconverted_lead(): void
    {
        $schema = AiDocumentSchema::create(['name' => 'Axis Template Data', 'is_active' => true, 'fields' => []]);

        $record = AiCustomerRecord::create([
            'schema_id' => $schema->id,
            'status' => 'approved',
            'data' => ['customer_name' => 'Ritu Malhotra', 'mobile_number' => '9123456780'],
        ]);

        $followUp = $this->followUp(['ai_customer_record_id' => $record->id]);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->assertSchemaStateSet([
                'customer_name' => 'Ritu Malhotra',
                'mobile_no' => '9123456780',
                'email' => null,
                'salary' => null,
            ]);
    }

    public function test_create_page_still_prefills_from_the_query_string_customer(): void
    {
        $customer = Customer::factory()->create([
            'employee_id' => $this->employee->id,
            'customer_name' => 'Kavita Reddy',
        ]);

        Livewire::withQueryParams(['customer' => $customer->id])
            ->test(CreateFollowUp::class)
            ->assertSchemaStateSet([
                'customer_name' => 'Kavita Reddy',
                'customer_id' => $customer->id,
            ]);
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function followUp(array $subject): FollowUp
    {
        return FollowUp::create([
            ...$subject,
            'employee_id' => $this->employee->id,
            'follow_up_type' => 'Email',
            'status' => 'Delay Multifunding',
            'remarks' => 'Customer wants disbursal next month.',
            'next_follow_up_date' => now()->addDays(3),
        ]);
    }
}
