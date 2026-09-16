<?php

namespace Tests\Feature;

use App\Enums\JourneyModule;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Services\OtherBankSupportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Other Bank Support sees and works every file eligible for a bank other
 * than the in-house BFL products — and nothing else — while the owner's
 * hierarchy keeps exactly the access it had.
 */
class OtherBankSupportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Manager', 'Caller', OtherBankSupportService::ROLE] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_support_user_sees_only_files_eligible_for_other_banks(): void
    {
        $hdfc = $this->customer(['bank_eligible_for' => 'HDFC Bank']);
        $other = $this->customer(['bank_eligible_for' => 'Other', 'other_bank_eligible_for' => 'Bajaj Housing']);

        $this->customer(['bank_eligible_for' => 'BFL Prime']);
        $this->customer(['bank_eligible_for' => 'BFL Growth']);
        $this->customer(['bank_eligible_for' => 'BFL RSL']);
        $this->customer(['bank_eligible_for' => 'BFL SOL']);
        $this->customer(['bank_eligible_for' => ' bfl-prime ']);
        $this->customer(['bank_eligible_for' => 'HDFC Bank', 'eligibility_status' => 'not_eligible']);
        $this->customer(['bank_eligible_for' => null]);

        $this->actingAs($this->supportUser());

        $this->assertEqualsCanonicalizing(
            [$hdfc->id, $other->id],
            CustomerResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    public function test_support_user_can_edit_other_bank_files_only_and_can_never_delete(): void
    {
        $otherBank = $this->customer(['bank_eligible_for' => 'Axis Bank']);
        $inHouse = $this->customer(['bank_eligible_for' => 'BFL Growth']);

        $this->actingAs($this->supportUser());

        $this->assertTrue(CustomerResource::canEdit($otherBank));
        $this->assertFalse(CustomerResource::canEdit($inHouse));
        $this->assertFalse(CustomerResource::canDelete($otherBank));
    }

    public function test_journey_access_decision_allows_support_user_on_other_bank_files_only(): void
    {
        $support = $this->supportUser();
        $service = app(CustomerJourneyAccessService::class);

        $otherBank = $this->customer(['bank_eligible_for' => 'Tata Capital', 'journey_status' => 'underwriting']);
        $inHouse = $this->customer(['bank_eligible_for' => 'BFL Prime', 'journey_status' => 'underwriting']);

        $this->assertTrue($service->decide($support, $otherBank, JourneyModule::Approval)->allowed);
        $this->assertFalse($service->decide($support, $inHouse, JourneyModule::Approval)->allowed);
    }

    public function test_admin_holding_the_support_role_still_sees_every_file(): void
    {
        $this->customer(['bank_eligible_for' => 'HDFC Bank']);
        $this->customer(['bank_eligible_for' => 'BFL Prime']);

        $admin = User::factory()->create();
        $admin->assignRole(['Admin', OtherBankSupportService::ROLE]);
        $this->actingAs($admin);

        $this->assertSame(2, CustomerResource::getEloquentQuery()->count());
    }

    public function test_owner_hierarchy_keeps_its_access_to_other_bank_files(): void
    {
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $manager->id,
        ]);

        $customer = $this->customer([
            'bank_eligible_for' => 'ICICI Bank',
            'assign_to' => $caller->id,
            'employee_id' => $caller->id,
        ]);

        $managerUser = User::factory()->create(['employee_id' => $manager->id]);
        $managerUser->assignRole('Manager');
        $this->actingAs($managerUser);

        $this->assertTrue(CustomerResource::getEloquentQuery()->whereKey($customer->id)->exists());
        $this->assertTrue(CustomerResource::canEdit($customer));

        $outsider = User::factory()->create([
            'employee_id' => Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER])->id,
        ]);
        $outsider->assignRole('Manager');
        $this->actingAs($outsider);

        $this->assertFalse(CustomerResource::getEloquentQuery()->whereKey($customer->id)->exists());
    }

    public function test_support_user_without_an_employee_profile_can_open_view_and_edit_pages(): void
    {
        $customer = $this->customer(['bank_eligible_for' => 'Kotak Mahindra Bank', 'journey_status' => 'sfl']);

        $this->actingAs($this->supportUser());

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->assertOk()
            ->assertSee('Other Bank Support Remarks');

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertOk()
            ->assertSee('Other Bank Support Remarks');
    }

    private function supportUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OtherBankSupportService::ROLE);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes): Customer
    {
        return Customer::factory()->create([
            'eligibility_status' => 'eligible',
            'journey_status' => 'sfl',
            ...$attributes,
        ]);
    }
}
