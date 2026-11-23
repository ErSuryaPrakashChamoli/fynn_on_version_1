<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\CustomerJourneyService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Moving a file Underwriting → Credit Approval → Disbursal: who may do the
 * Disbursal step, and that no stage button moves a file on while a required
 * box of its step is empty.
 */
class CustomerJourneyStageMoveTest extends TestCase
{
    use RefreshDatabase;

    private Employee $businessHead;

    private Employee $clusterManager;

    private Employee $teamLeader;

    private Employee $caller;

    private User $businessHeadUser;

    private User $clusterManagerUser;

    private User $teamLeaderUser;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Business Head', 'Cluster Manager', 'Manager', 'Team Leader', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Skip-level branch: the Team Leader reports straight to the Cluster
        // Manager, so there is no Manager on this file's chain at all.
        $this->businessHead = Employee::factory()->create(['designation' => Employee::DESIGNATION_BUSINESS_HEAD]);
        $this->clusterManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
            'business_head_id' => $this->businessHead->id,
        ]);
        $this->teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'cluster_id' => $this->clusterManager->id,
            'business_head_id' => $this->businessHead->id,
        ]);
        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'cluster_id' => $this->clusterManager->id,
            'business_head_id' => $this->businessHead->id,
        ]);

        $this->businessHeadUser = $this->userFor($this->businessHead, 'Business Head');
        $this->clusterManagerUser = $this->userFor($this->clusterManager, 'Cluster Manager');
        $this->teamLeaderUser = $this->userFor($this->teamLeader, 'Team Leader');
    }

    public function test_a_cluster_manager_with_no_manager_below_gets_step_4_and_finalizes_disbursal(): void
    {
        $customer = $this->approvedCustomer();
        $this->actingAs($this->clusterManagerUser);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertSee('Step 4: Disbursal Payouts')
            ->fillForm($this->stepFour())
            ->callAction($this->finalizeAction())
            ->assertNotified('Disbursal status updated successfully.');

        $customer->refresh();
        $this->assertSame('sanctioned', $customer->journey_status);
        $this->assertSame('disbursed', $customer->disbursal_status);
        $this->assertSame(900000.0, (float) $customer->sanctioned_loan_amount);
    }

    public function test_a_business_head_gets_step_4_but_a_team_leader_still_does_not(): void
    {
        $customer = $this->approvedCustomer();

        $this->actingAs($this->businessHeadUser);
        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertSee('Step 4: Disbursal Payouts');

        $this->actingAs($this->teamLeaderUser);
        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertDontSee('Step 4: Disbursal Payouts');
    }

    public function test_the_approve_button_will_not_move_a_file_with_empty_credit_approval_boxes(): void
    {
        $customer = $this->underwritingCustomer();
        $this->actingAs($this->clusterManagerUser);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->fillForm([
                'approval_date' => today()->toDateString(),
                'underwriting_remarks' => 'Case approved.',
                'approved_loan_amount' => null,
                'approved_remarks' => null,
            ])
            ->callAction($this->approveAction())
            ->assertHasFormErrors(['approved_loan_amount', 'approved_remarks'])
            ->assertNotified();

        $this->assertSame('underwriting', $customer->fresh()->journey_status);
    }

    public function test_the_approve_button_moves_a_complete_file_to_credit_approval(): void
    {
        $customer = $this->underwritingCustomer();
        $this->actingAs($this->clusterManagerUser);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->fillForm([
                'approval_date' => today()->toDateString(),
                'underwriting_remarks' => 'Case approved.',
                'approved_loan_amount' => '9,11,441',
                'approved_remarks' => 'Case approved and dispatched.',
            ])
            ->callAction($this->approveAction())
            ->assertHasNoFormErrors();

        $customer->refresh();
        $this->assertSame('approved', $customer->journey_status);
        $this->assertSame(911441.0, (float) $customer->approved_loan_amount);
        $this->assertSame('HDFC', $customer->sanctioned_bank);
    }

    public function test_finalize_disbursal_will_not_move_a_file_with_empty_disbursal_boxes(): void
    {
        $customer = $this->approvedCustomer();
        $this->actingAs($this->clusterManagerUser);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->fillForm(['disbursal_status' => 'disbursed', 'cashback_option' => 'none'])
            ->callAction($this->finalizeAction())
            ->assertHasFormErrors(['disbursal_date', 'channel', 'sanctioned_loan_amount', 'subvention', 'sanctioned_remarks'])
            ->assertNotified();

        $customer->refresh();
        $this->assertSame('approved', $customer->journey_status);
        $this->assertFalse((bool) $customer->disbursal_finalized);
    }

    public function test_the_service_refuses_to_approve_without_the_credit_approval_details(): void
    {
        $customer = $this->underwritingCustomer(['bank_eligible_for' => null]);

        try {
            CustomerJourneyService::approve($customer, []);
            $this->fail('Approving without the credit approval details must be refused.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(
                ['approval_date', 'underwriting_remarks', 'approved_loan_amount', 'sanctioned_bank', 'approved_remarks'],
                array_keys($exception->errors()),
            );
        }

        try {
            CustomerJourneyService::approve($customer, $this->stepThree(['sanctioned_bank' => 'other', 'approved_loan_amount' => '0']));
            $this->fail('A zero amount and an unnamed "Other" bank must be refused.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(['approved_loan_amount', 'other_sanctioned_bank'], array_keys($exception->errors()));
        }

        $this->assertSame('underwriting', $customer->fresh()->journey_status);
    }

    public function test_the_service_refuses_to_disburse_without_the_disbursal_details(): void
    {
        $customer = $this->approvedCustomer();

        try {
            CustomerJourneyService::sanction($customer, ['disbursal_status' => 'disbursed', 'cashback_option' => 'none']);
            $this->fail('Disbursing without the disbursal details must be refused.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(
                ['disbursal_date', 'channel', 'sanctioned_loan_amount', 'subvention', 'sanctioned_remarks'],
                array_keys($exception->errors()),
            );
        }

        try {
            CustomerJourneyService::sanction($customer, ['disbursal_status' => 'carry_forward', 'sanctioned_remarks' => 'Next month.']);
            $this->fail('A carry forward needs its date.');
        } catch (ValidationException $exception) {
            $this->assertSame(['carry_forward_date'], array_keys($exception->errors()));
        }

        try {
            CustomerJourneyService::sanction($customer, []);
            $this->fail('A disbursal status is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('disbursal_status', $exception->errors());
        }

        $this->assertSame('approved', $customer->fresh()->journey_status);
    }

    public function test_an_on_hold_file_stays_in_approval(): void
    {
        $customer = CustomerJourneyService::sanction($this->approvedCustomer(), [
            'disbursal_status' => 'on_hold',
            'sanctioned_remarks' => 'Customer travelling.',
        ]);

        $this->assertSame('approved', $customer->journey_status);
        $this->assertSame('on_hold', $customer->disbursal_status);
    }

    public function test_an_empty_required_box_stays_open_after_its_step_while_a_filled_one_locks(): void
    {
        $customer = $this->approvedCustomer([
            'lan_no' => null,
            'approval_date' => null,
            'approved_remarks' => null,
        ]);
        $this->actingAs($this->clusterManagerUser);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertFormFieldEnabled('lan_no')
            ->assertFormFieldEnabled('approval_date')
            ->assertFormFieldEnabled('approved_remarks')
            ->assertFormFieldDisabled('sfl_remarks')
            ->assertFormFieldDisabled('underwriting_status')
            ->assertFormFieldDisabled('underwriting_remarks')
            ->assertFormFieldDisabled('approved_loan_amount')
            ->assertFormFieldDisabled('sanctioned_bank');
    }

    private function approveAction(): TestAction
    {
        return TestAction::make('promote_to_approval')->schemaComponent('underwriting_actions', schema: 'form');
    }

    private function finalizeAction(): TestAction
    {
        return TestAction::make('finalize_disbursal')->schemaComponent('disbursal_actions', schema: 'form');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function stepThree(array $overrides = []): array
    {
        return array_merge([
            'approval_date' => today()->toDateString(),
            'underwriting_remarks' => 'Case approved.',
            'approved_loan_amount' => '9,11,441',
            'sanctioned_bank' => 'HDFC',
            'approved_remarks' => 'Case approved and dispatched.',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function stepFour(): array
    {
        return [
            'disbursal_status' => 'disbursed',
            'disbursal_date' => today()->toDateString(),
            'channel' => 'ruloans',
            'sanctioned_loan_amount' => '9,00,000',
            'cashback_option' => 'none',
            'subvention' => '0',
            'sanctioned_remarks' => 'Disbursed.',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function underwritingCustomer(array $attributes = []): Customer
    {
        return $this->customer([
            'journey_status' => 'underwriting',
            'underwriting_status' => 'approved',
            'approval_date' => null,
            'underwriting_remarks' => null,
            'approved_loan_amount' => null,
            'sanctioned_bank' => null,
            'approved_remarks' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function approvedCustomer(array $attributes = []): Customer
    {
        return $this->customer([
            'journey_status' => 'approved',
            'underwriting_status' => 'approved',
            'approval_date' => today()->subDay()->toDateString(),
            'underwriting_remarks' => 'Case approved.',
            'approved_loan_amount' => 911441,
            'sanctioned_bank' => 'HDFC',
            'approved_remarks' => 'Case approved and dispatched.',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
            'eligibility_status' => 'eligible',
            'eligibility_reason' => null,
            'bank_eligible_for' => 'HDFC',
            'lan_no' => 'LAN-1001',
            'eligible_loan_amount' => 1000000,
            'documentation_status' => 'complete',
            'sfl_remarks' => 'Documents verified.',
            'sanctioned_loan_amount' => null,
            'cashback' => null,
            'subvention' => null,
            'docking' => null,
            ...$attributes,
        ]);
    }

    private function userFor(Employee $employee, string $role): User
    {
        $user = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
