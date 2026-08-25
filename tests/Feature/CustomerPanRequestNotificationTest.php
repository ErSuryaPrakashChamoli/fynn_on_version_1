<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\CustomerPanRequest;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerPanRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_are_notified_when_an_employee_submits_a_duplicate_pan_request(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $admins = User::factory()->count(2)->create();
        $admins->each(fn (User $admin) => $admin->assignRole('Admin'));

        $employee = Employee::factory()->create();
        $caller = User::factory()->create(['employee_id' => $employee->id]);

        $existingCustomer = Customer::factory()->create(['pan_number' => 'ABCDE1234F']);

        $lead = Lead::create([
            'customer_name' => 'Jane Doe',
            'mobile_no' => '9876543210',
            'pan_number' => 'ABCDE1234F',
            'follow_up_date' => now()->toDateString(),
            'follow_up_type' => 'Call',
            'remarks' => 'Test lead',
        ]);

        $bank = Bank::create(['bank_name' => 'Test Bank', 'loan_type' => 'personal_loan']);

        $this->actingAs($caller);

        Livewire::withQueryParams(['lead' => $lead->id]);

        Livewire::test(CreateCustomer::class)
            ->assertSet('existingCustomer.id', $existingCustomer->id)
            ->callAction(TestAction::make('requestApproval')->schemaComponent('existingCustomerSection'), [
                'requested_bank_id' => $bank->id,
                'requested_loan_type' => 'personal_loan',
                'reason' => 'Customer wants to reapply.',
            ]);

        $this->assertDatabaseHas('customer_pan_requests', [
            'customer_id' => $existingCustomer->id,
            'requested_by' => $employee->id,
            'status' => CustomerPanRequest::STATUS_PENDING,
        ]);

        foreach ($admins as $admin) {
            $notification = $admin->fresh()->notifications->first();

            $this->assertNotNull($notification, "Admin {$admin->id} was not notified.");
            $this->assertSame('New Duplicate PAN Request', $notification->data['title']);
        }
    }
}
