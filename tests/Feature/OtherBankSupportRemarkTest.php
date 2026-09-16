<?php

namespace Tests\Feature;

use App\Enums\OtherBankRemarkStage;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\OtherBankSupportRemark;
use App\Models\User;
use App\Services\OtherBankSupportService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Other Bank Support writes step-wise remarks on other-bank files; the file
 * owner and everyone above them read them on the customer's pages.
 */
class OtherBankSupportRemarkTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $caller;

    private User $managerUser;

    private User $callerUser;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Manager', 'Caller', OtherBankSupportService::ROLE] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $this->manager->id,
        ]);

        $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id]);
        $this->managerUser->assignRole('Manager');

        $this->callerUser = User::factory()->create(['employee_id' => $this->caller->id]);
        $this->callerUser->assignRole('Caller');
    }

    public function test_support_user_adds_a_remark_from_the_edit_page(): void
    {
        $customer = $this->otherBankCustomer(['journey_status' => 'underwriting']);

        $this->actingAs($this->supportUser());

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->callAction(
                TestAction::make('addOtherBankRemark')->schemaComponent('otherBankSupportRemarks'),
                ['stage' => OtherBankRemarkStage::Underwriting->value, 'remark' => 'Login done with HDFC, awaiting CIBIL.'],
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('other_bank_support_remarks', [
            'customer_id' => $customer->id,
            'stage' => OtherBankRemarkStage::Underwriting->value,
            'remark' => 'Login done with HDFC, awaiting CIBIL.',
        ]);
    }

    public function test_remark_requires_text(): void
    {
        $customer = $this->otherBankCustomer();

        $this->actingAs($this->supportUser());

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->callAction(
                TestAction::make('addOtherBankRemark')->schemaComponent('otherBankSupportRemarks'),
                ['stage' => OtherBankRemarkStage::Sfl->value, 'remark' => ''],
            )
            ->assertHasActionErrors(['remark' => 'required']);

        $this->assertDatabaseCount('other_bank_support_remarks', 0);
    }

    public function test_owner_caller_and_their_manager_read_remarks_but_cannot_add_them(): void
    {
        $customer = $this->otherBankCustomer();

        OtherBankSupportRemark::factory()->create([
            'customer_id' => $customer->id,
            'stage' => OtherBankRemarkStage::Sfl,
            'remark' => 'Salary slips pending from customer.',
        ]);

        foreach ([$this->callerUser, $this->managerUser] as $viewer) {
            $this->actingAs($viewer);

            Livewire::test(ViewCustomer::class, ['record' => $customer->id])
                ->assertOk()
                ->assertSee('Salary slips pending from customer.')
                ->assertDontSee('Add Remark');
        }
    }

    public function test_remarks_section_is_not_shown_on_in_house_files(): void
    {
        $customer = $this->otherBankCustomer(['bank_eligible_for' => 'BFL Prime']);

        $this->actingAs($this->callerUser);

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->assertOk()
            ->assertDontSee('Other Bank Support Remarks');
    }

    public function test_service_refuses_remarks_on_in_house_files_and_from_other_roles(): void
    {
        $inHouse = $this->otherBankCustomer(['bank_eligible_for' => 'BFL SOL']);

        try {
            app(OtherBankSupportService::class)->addRemark($this->supportUser(), $inHouse, OtherBankRemarkStage::Sfl, 'x');
            $this->fail('A remark on an in-house file must be refused.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->expectException(AuthorizationException::class);

        app(OtherBankSupportService::class)->addRemark($this->managerUser, $this->otherBankCustomer(), OtherBankRemarkStage::Sfl, 'x');
    }

    public function test_adding_a_remark_notifies_the_owner_and_everyone_above_them(): void
    {
        $customer = $this->otherBankCustomer();
        $support = $this->supportUser();

        app(OtherBankSupportService::class)->addRemark($support, $customer, OtherBankRemarkStage::Disbursal, 'Disbursal expected Friday.');

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->callerUser->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->managerUser->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $support->id]);
    }

    public function test_remarks_are_grouped_by_step_in_journey_order(): void
    {
        $customer = $this->otherBankCustomer();

        OtherBankSupportRemark::factory()->create(['customer_id' => $customer->id, 'stage' => OtherBankRemarkStage::Disbursal]);
        OtherBankSupportRemark::factory()->count(2)->create(['customer_id' => $customer->id, 'stage' => OtherBankRemarkStage::Sfl]);

        $grouped = app(OtherBankSupportService::class)->remarksByStage($customer);

        $this->assertSame(['sfl', 'underwriting', 'credit_approval', 'disbursal'], array_keys($grouped));
        $this->assertCount(2, $grouped['sfl']['remarks']);
        $this->assertCount(0, $grouped['underwriting']['remarks']);
        $this->assertCount(1, $grouped['disbursal']['remarks']);
    }

    public function test_default_step_follows_the_journey_status(): void
    {
        $this->assertSame(OtherBankRemarkStage::Sfl, OtherBankRemarkStage::forJourneyStatus('sfl'));
        $this->assertSame(OtherBankRemarkStage::Underwriting, OtherBankRemarkStage::forJourneyStatus('not_approved'));
        $this->assertSame(OtherBankRemarkStage::CreditApproval, OtherBankRemarkStage::forJourneyStatus('approved'));
        $this->assertSame(OtherBankRemarkStage::Disbursal, OtherBankRemarkStage::forJourneyStatus('sanctioned'));
        $this->assertSame(OtherBankRemarkStage::Sfl, OtherBankRemarkStage::forJourneyStatus(null));
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
    private function otherBankCustomer(array $attributes = []): Customer
    {
        return Customer::factory()->create([
            'eligibility_status' => 'eligible',
            'bank_eligible_for' => 'HDFC Bank',
            'journey_status' => 'sfl',
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
            ...$attributes,
        ]);
    }
}
