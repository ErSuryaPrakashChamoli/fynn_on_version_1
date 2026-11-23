<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerCashbackRequests\Pages\CreateCustomerCashbackRequest;
use App\Filament\Resources\CustomerCashbackRequests\Pages\ViewCustomerCashbackRequest;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\CustomerCashbackService;
use App\Services\CustomerEditRequestService;
use App\Services\CustomerJourneyService;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A cashback form also declares the case's subvention: the case's
 * subvention follows the latest live declaring form at once (pending counts,
 * like cashback), a rejected or retrieved form puts the earlier figure back,
 * and Step 4 / edit requests no longer overwrite it.
 */
class CashbackSubventionDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    private User $teamLeaderUser;

    private User $managerUser;

    private User $adminUser;

    private Customer $customer;

    private CustomerCashbackService $cashback;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-21 12:00:00'));

        foreach (['Admin', 'Accounts', 'Cluster Manager', 'Manager', 'Team Leader', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $onRolls = ['reporting_date' => '2026-01-01', 'doj' => '2026-01-01'];
        $manager = Employee::factory()->create([...$onRolls, 'designation' => Employee::DESIGNATION_MANAGER, 'emp_name' => 'Meena Floor']);
        $teamLeader = Employee::factory()->create([...$onRolls, 'designation' => Employee::DESIGNATION_TEAM_LEADER, 'manager_id' => $manager->id, 'emp_name' => 'Tarun Lead']);
        $this->caller = Employee::factory()->create([...$onRolls, 'designation' => Employee::DESIGNATION_CALLER, 'superviser_id' => $teamLeader->id, 'manager_id' => $manager->id, 'emp_name' => 'Asha Caller']);

        $this->teamLeaderUser = $this->userFor($teamLeader, 'Team Leader');
        $this->managerUser = $this->userFor($manager, 'Manager');
        $this->adminUser = User::factory()->create(['is_active' => true]);
        $this->adminUser->assignRole('Admin');

        $this->customer = $this->disbursedCase(subvention: 1000);
        $this->cashback = app(CustomerCashbackService::class);
    }

    public function test_the_form_declares_the_cases_subvention_and_it_counts_at_once(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['subvention_amount' => '2,500']));

        $this->assertTrue($form->isPending());
        $this->assertSame(2500.0, (float) $form->subvention_amount);

        $customer = $this->customer->fresh();
        $this->assertSame(2500.0, (float) $customer->subvention);
        $this->assertSame(1000.0, (float) $customer->subvention_before_forms);
        $this->assertSame(5000.0, (float) $customer->cashback);

        $this->assertStringContainsString('Subvention on the case: ₹2,500.', (string) $this->managerUser->notifications()->first()->data['body']);
    }

    public function test_rejecting_the_form_puts_the_earlier_subvention_back(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['subvention_amount' => '2500']));

        $this->cashback->reject($form, $this->managerUser, 'Subvention too high.');

        $this->assertSame(1000.0, (float) $this->customer->fresh()->subvention);
    }

    public function test_the_latest_live_form_carries_the_subvention_and_each_retreat_falls_back_one_step(): void
    {
        $expected = $this->cashback->raise($this->teamLeaderUser, $this->customer, ['type' => 'expected', 'amount' => '3000', 'subvention_amount' => '2000']);
        $this->cashback->approve($expected, $this->managerUser);
        $this->cashback->approve($expected->fresh(), $this->adminUser);

        $second = $this->cashback->raise($this->teamLeaderUser, $this->customer->fresh(), $this->upiForm(['subvention_amount' => '3000']));
        $this->assertSame(3000.0, (float) $this->customer->fresh()->subvention);

        $this->cashback->reject($second, $this->managerUser, 'Not agreed.');
        $this->assertSame(2000.0, (float) $this->customer->fresh()->subvention);

        $this->cashback->retrieve($expected->fresh(), $this->teamLeaderUser, 'Not needed any more.');
        $this->assertSame(1000.0, (float) $this->customer->fresh()->subvention);
    }

    public function test_a_form_without_a_declaration_leaves_the_subvention_alone(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm());

        $this->assertNull($form->subvention_amount);
        $this->assertFalse($form->declaresSubvention());

        $customer = $this->customer->fresh();
        $this->assertSame(1000.0, (float) $customer->subvention);
        $this->assertNull($customer->subvention_before_forms);
        $this->assertFalse($this->cashback->subventionFollowsForms($customer));
    }

    public function test_zero_is_a_declaration_and_a_negative_one_is_refused(): void
    {
        try {
            $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['subvention_amount' => '-5']));
            $this->fail('A negative subvention must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subvention_amount', $exception->errors());
        }

        $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['subvention_amount' => '0']));

        $this->assertSame(0.0, (float) $this->customer->fresh()->subvention);
    }

    public function test_step_4_keeps_the_subvention_a_live_form_declares(): void
    {
        $case = $this->stepFourCase();
        $this->cashback->raise($this->teamLeaderUser, $case, ['type' => 'expected', 'amount' => '2000', 'subvention_amount' => '4000']);

        $sanctioned = CustomerJourneyService::sanction($case->fresh(), $this->stepFour(['cashback_option' => 'expected', 'subvention' => '9000']));

        $this->assertSame('sanctioned', $sanctioned->journey_status);
        $this->assertSame(4000.0, (float) $sanctioned->subvention);
    }

    public function test_step_4_types_the_subvention_when_no_live_form_declares_it_and_that_is_the_fallback(): void
    {
        $case = $this->stepFourCase();
        $rejected = $this->cashback->raise($this->teamLeaderUser, $case, ['type' => 'expected', 'amount' => '2000', 'subvention_amount' => '4000']);
        $this->cashback->reject($rejected, $this->managerUser, 'Not needed.');

        $sanctioned = CustomerJourneyService::sanction($case->fresh(), $this->stepFour(['cashback_option' => 'none', 'subvention' => '9,000']));

        $this->assertSame(9000.0, (float) $sanctioned->subvention);
        $this->assertSame(9000.0, (float) $sanctioned->subvention_before_forms);

        $later = $this->cashback->raise($this->teamLeaderUser, $sanctioned, $this->upiForm(['subvention_amount' => '7000']));
        $this->assertSame(7000.0, (float) $case->fresh()->subvention);

        $this->cashback->reject($later, $this->managerUser, 'No.');
        $this->assertSame(9000.0, (float) $case->fresh()->subvention);
    }

    public function test_the_step_4_subvention_box_is_read_only_while_a_live_form_declares_it(): void
    {
        $case = $this->stepFourCase();
        $this->actingAs($this->managerUser);

        Livewire::test(EditCustomer::class, ['record' => $case->id])
            ->fillForm(['disbursal_status' => 'disbursed'])
            ->assertFormFieldEnabled('subvention');

        $this->cashback->raise($this->teamLeaderUser, $case, ['type' => 'expected', 'amount' => '2000', 'subvention_amount' => '4000']);

        Livewire::test(EditCustomer::class, ['record' => $case->id])
            ->fillForm(['disbursal_status' => 'disbursed'])
            ->assertFormFieldDisabled('subvention')
            ->assertSee('Declared on the case');
    }

    public function test_an_edit_request_cannot_change_a_subvention_declared_on_forms(): void
    {
        $edits = app(CustomerEditRequestService::class);
        $askedBefore = $edits->raise($this->teamLeaderUser, $this->customer, 'step_4_disbursal', 'Typo.', [['field' => 'subvention', 'value' => '1500']]);

        $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['subvention_amount' => '2000']));

        try {
            $edits->approve($askedBefore, $this->adminUser);
            $this->fail('A request raised before the form must not overwrite the declared subvention.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('declared on its cashback forms', implode(' ', $exception->errors()['changes']));
        }

        try {
            $edits->raise($this->teamLeaderUser, $this->customer->fresh(), 'step_4_disbursal', 'Again.', [['field' => 'subvention', 'value' => '1500']]);
            $this->fail('A new request must be refused too.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changes.0.field', $exception->errors());
        }

        $this->assertSame(2000.0, (float) $this->customer->fresh()->subvention);
    }

    public function test_the_create_page_prefills_the_cases_subvention_and_requires_one(): void
    {
        $this->actingAs($this->teamLeaderUser);

        Livewire::test(CreateCustomerCashbackRequest::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSchemaStateSet(['subvention_amount' => '1,000'])
            ->assertSee('Subvention on the case now')
            ->fillForm(['type' => 'cashback', 'amount' => '5000', 'payment_mode' => 'cash', 'subvention_amount' => null])
            ->call('create')
            ->assertHasFormErrors(['subvention_amount' => 'required'])
            ->fillForm(['subvention_amount' => '1,750'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1750.0, (float) $this->customer->fresh()->subvention);
        $this->assertSame(1750.0, (float) $this->customer->fresh()->cashbackRequests()->sole()->subvention_amount);
    }

    public function test_the_customer_page_form_prefills_and_saves_the_subvention(): void
    {
        $this->actingAs($this->teamLeaderUser);

        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->mountAction(TestAction::make('fillCashbackForm')->schemaComponent('customerCashback'))
            ->assertActionDataSet(['subvention_amount' => '1,000'])
            ->setActionData(['type' => 'cashback', 'amount' => '4000', 'payment_mode' => 'cash', 'subvention_amount' => '3,000'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(3000.0, (float) $this->customer->fresh()->subvention);
    }

    public function test_the_voucher_shows_the_declared_subvention(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['subvention_amount' => '2500']));

        $this->actingAs($this->managerUser);

        Livewire::test(ViewCustomerCashbackRequest::class, ['record' => $form->id])
            ->assertSee('Subvention on the case')
            ->assertSee('₹2,500')
            ->assertSee('subvention declared ₹2,500');
    }

    private function disbursedCase(float $subvention): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
            'journey_status' => 'sanctioned',
            'disbursal_status' => 'disbursed',
            'disbursal_finalized' => true,
            'disbursal_date' => '2026-11-05',
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => 1000000,
            'cashback' => null,
            'subvention' => $subvention,
            'docking' => 0,
        ]);
    }

    private function stepFourCase(): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
            'journey_status' => 'approved',
            'approval_date' => '2026-11-18',
            'approved_loan_amount' => 600000,
            'sanctioned_bank' => 'HDFC Bank',
            'cashback' => null,
            'subvention' => null,
            'docking' => 0,
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function stepFour(array $overrides): array
    {
        return array_merge([
            'disbursal_status' => 'disbursed',
            'disbursal_date' => today()->toDateString(),
            'channel' => 'ruloans',
            'sanctioned_loan_amount' => '600000',
            'docking' => '0',
            'sanctioned_remarks' => 'Disbursed.',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function upiForm(array $overrides = []): array
    {
        return array_merge([
            'type' => 'cashback',
            'amount' => '5000',
            'payment_mode' => 'upi',
            'upi_platform' => 'gpay',
            'payee_upi' => '9876543210@okaxis',
        ], $overrides);
    }

    private function userFor(Employee $employee, string $role): User
    {
        $user = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
