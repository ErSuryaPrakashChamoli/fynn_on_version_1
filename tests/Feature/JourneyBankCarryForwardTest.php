<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\CustomerJourneyService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JourneyBankCarryForwardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_approval_bank_is_preselected_from_the_sfl_bank(): void
    {
        $customer = $this->underwritingCustomer(['sanctioned_bank' => null]);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertSchemaStateSet(['sanctioned_bank' => 'ABFL']);
    }

    public function test_an_already_chosen_approval_bank_is_kept(): void
    {
        $customer = $this->underwritingCustomer(['sanctioned_bank' => 'HDFC Bank']);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertSchemaStateSet(['sanctioned_bank' => 'HDFC Bank']);
    }

    public function test_approving_without_a_bank_falls_back_to_the_sfl_bank(): void
    {
        $customer = $this->underwritingCustomer(['sanctioned_bank' => null]);

        $approved = CustomerJourneyService::approve($customer, $this->approvalData(['sanctioned_bank' => null]));

        $this->assertSame('ABFL', $approved->sanctioned_bank);
    }

    public function test_approving_with_a_different_bank_keeps_that_choice(): void
    {
        $customer = $this->underwritingCustomer(['sanctioned_bank' => null]);

        $approved = CustomerJourneyService::approve($customer, $this->approvalData(['sanctioned_bank' => 'HDFC Bank']));

        $this->assertSame('HDFC Bank', $approved->sanctioned_bank);
    }

    /**
     * The rest of the Step 2 / Step 3 boxes approve() requires.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function approvalData(array $overrides): array
    {
        return array_merge([
            'approval_date' => today()->toDateString(),
            'underwriting_remarks' => 'Approved.',
            'approved_loan_amount' => '500000',
            'approved_remarks' => 'Approved.',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function underwritingCustomer(array $attributes): Customer
    {
        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        return Customer::factory()->create([
            'assign_to' => $employee->id,
            'employee_id' => $employee->id,
            'eligibility_status' => 'eligible',
            'bank_eligible_for' => 'ABFL',
            'journey_status' => 'underwriting',
            'underwriting_status' => 'approved',
            ...$attributes,
        ]);
    }
}
