<?php

namespace Tests\Feature;

use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Enums\CashbackType;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use App\Filament\Resources\CustomerCashbackRequests\Pages\CreateCustomerCashbackRequest;
use App\Filament\Resources\CustomerCashbackRequests\Pages\ListCustomerCashbackRequests;
use App\Filament\Resources\CustomerCashbackRequests\Pages\ViewCustomerCashbackRequest;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\CustomerCashbackRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\CustomerCashbackService;
use App\Services\CustomerEditRequestService;
use App\Services\CustomerJourneyService;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The digital cashback form: a Team Leader or Manager fills it on a file
 * declared for cashback / expected cashback, it goes Manager → Admin, the
 * file's cashback (and so the achievement figures) follows the live form,
 * expected cashback can be retrieved, only the Admin changes an approved
 * amount, and the Admin / Accounts record the payment.
 */
class CustomerCashbackFormTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    private Employee $teamLeader;

    private Employee $manager;

    private Employee $clusterManager;

    private User $clusterManagerUser;

    private User $callerUser;

    private User $teamLeaderUser;

    private User $managerUser;

    private User $adminUser;

    private User $accountsUser;

    private User $outsiderUser;

    private Customer $customer;

    private CustomerCashbackService $cashback;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Accounts', 'Cluster Manager', 'Manager', 'Team Leader', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER, 'emp_name' => 'Kavita Cluster']);
        $this->manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'cluster_id' => $this->clusterManager->id, 'emp_name' => 'Meena Floor']);
        $this->teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'manager_id' => $this->manager->id, 'emp_name' => 'Tarun Lead']);
        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'emp_name' => 'Asha Caller',
        ]);

        $this->callerUser = $this->userFor($this->caller, 'Caller');
        $this->teamLeaderUser = $this->userFor($this->teamLeader, 'Team Leader');
        $this->managerUser = $this->userFor($this->manager, 'Manager');
        $this->clusterManagerUser = $this->userFor($this->clusterManager, 'Cluster Manager');
        $this->adminUser = User::factory()->create(['is_active' => true]);
        $this->adminUser->assignRole('Admin');
        $this->accountsUser = User::factory()->create(['is_active' => true]);
        $this->accountsUser->assignRole('Accounts');
        $this->outsiderUser = $this->userFor(Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]), 'Manager');

        $this->customer = Customer::factory()->create([
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
            'customer_name' => 'Ravi Sharma',
            'pan_number' => 'ABCDE1234F',
            'mobile_no' => '9876543210',
            'journey_status' => 'approved',
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => 500000,
            'disbursal_date' => now(),
            'cashback' => null,
            'subvention' => 0,
            'docking' => 0,
        ]);

        $this->cashback = app(CustomerCashbackService::class);
    }

    public function test_a_team_leader_fills_the_form_and_the_file_carries_its_amount_while_it_waits_for_the_manager(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['amount' => '5,000']));

        $this->assertSame(CashbackRequestStatus::PendingManager, $form->status);
        $this->assertSame('CB-'.str_pad((string) $form->id, 6, '0', STR_PAD_LEFT), $form->voucher_no);
        $this->assertSame($this->caller->id, $form->aro_employee_id);
        $this->assertSame($this->teamLeader->id, $form->team_leader_employee_id);
        $this->assertSame($this->manager->id, $form->manager_employee_id);
        $this->assertSame(CashbackPaymentStatus::NotPaid, $form->payment_status);

        $customer = $this->customer->fresh();
        $this->assertSame(5000.0, (float) $customer->cashback);
        $this->assertSame('cashback', $customer->cashback_option);

        $this->assertSame(1, $this->managerUser->notifications()->count());
        $this->assertSame('cashback', $this->managerUser->notifications()->first()->category);
        $this->assertSame(0, $this->adminUser->notifications()->count());
    }

    public function test_the_manager_then_the_admin_approve_and_the_admin_can_set_the_amount(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['amount' => '5000']));

        $this->assertFalse($this->cashback->canApprove($this->teamLeaderUser, $form));
        $this->assertFalse($this->cashback->canApprove($this->outsiderUser, $form));

        $this->cashback->approve($form, $this->managerUser, 'Customer was promised this.');
        $form->refresh();

        $this->assertSame(CashbackRequestStatus::PendingAdmin, $form->status);
        $this->assertSame($this->managerUser->id, $form->manager_approved_by);
        $this->assertNotNull($form->manager_approved_at);
        $this->assertFalse($this->cashback->canApprove($this->managerUser, $form));
        $this->assertSame(1, $this->adminUser->notifications()->count());

        $this->cashback->approve($form, $this->adminUser, 'OK', '4,500');
        $form->refresh();

        $this->assertSame(CashbackRequestStatus::Approved, $form->status);
        $this->assertSame(4500.0, (float) $form->approved_amount);
        $this->assertSame($this->adminUser->id, $form->approved_by);
        $this->assertSame(4500.0, (float) $this->customer->fresh()->cashback);
        $this->assertSame(1, $this->accountsUser->notifications()->count());
    }

    public function test_approving_and_retrieving_work_when_no_accounts_role_exists(): void
    {
        $this->accountsUser->removeRole('Accounts');
        Role::findByName('Accounts')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, ['type' => 'expected', 'amount' => '3000']);
        $this->cashback->approve($form, $this->managerUser);
        $this->cashback->approve($form->fresh(), $this->adminUser);

        $this->assertSame(CashbackRequestStatus::Approved, $form->fresh()->status);

        $this->cashback->retrieve($form->fresh(), $this->teamLeaderUser, 'Not needed.');

        $this->assertSame(CashbackRequestStatus::Retrieved, $form->fresh()->status);
        $this->assertSame(1, $this->adminUser->notifications()->where('data->title', 'like', 'Expected cashback retrieved%')->count());
    }

    public function test_a_managers_form_goes_straight_to_the_admin_and_the_admins_own_is_approved_at_once(): void
    {
        $form = $this->cashback->raise($this->managerUser, $this->customer, $this->upiForm());

        $this->assertSame(CashbackRequestStatus::PendingAdmin, $form->status);
        $this->assertSame($this->managerUser->id, $form->manager_approved_by);

        $this->cashback->reject($form, $this->adminUser, 'Duplicate.');

        $adminForm = $this->cashback->raise($this->adminUser, $this->customer, $this->upiForm(['amount' => '2000']));

        $this->assertSame(CashbackRequestStatus::Approved, $adminForm->status);
        $this->assertSame(2000.0, (float) $adminForm->approved_amount);
        $this->assertNull($adminForm->manager_approved_by);
    }

    public function test_only_the_files_team_leader_and_above_can_fill_it_and_only_one_form_waits_at_a_time(): void
    {
        $this->assertFalse($this->cashback->canRaiseOn($this->callerUser, $this->customer));
        $this->assertFalse($this->cashback->canRaiseOn($this->outsiderUser, $this->customer));
        $this->assertTrue($this->cashback->canRaiseOn($this->teamLeaderUser, $this->customer));

        try {
            $this->cashback->raise($this->callerUser, $this->customer, $this->upiForm());
            $this->fail('A caller must not fill the cashback form.');
        } catch (AuthorizationException) {
        }

        $first = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm());
        $this->assertFalse($this->cashback->canRaiseOn($this->managerUser, $this->customer->fresh()));

        try {
            $this->cashback->raise($this->managerUser, $this->customer, $this->upiForm());
            $this->fail('A second form must wait until the first is approved or rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($first->voucher_no, $exception->getMessage());
        }

        $this->cashback->approve($first, $this->adminUser);

        $this->assertTrue($this->cashback->canRaiseOn($this->managerUser, $this->customer->fresh()));
    }

    public function test_a_file_before_the_disbursal_step_cannot_get_a_form(): void
    {
        $this->customer->update(['journey_status' => 'underwriting']);

        $this->assertFalse($this->cashback->canRaiseOn($this->teamLeaderUser, $this->customer));

        $this->expectException(ValidationException::class);
        $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidForms')]
    public function test_the_form_is_validated(array $overrides, string $errorKey): void
    {
        try {
            $this->cashback->raise($this->teamLeaderUser, $this->customer, array_merge($this->upiForm(), $overrides));
            $this->fail('Expected a validation error on '.$errorKey);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        $this->assertSame(0, CustomerCashbackRequest::query()->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidForms(): array
    {
        return [
            'no declaration' => [['type' => null], 'type'],
            'none is not a form' => [['type' => 'none'], 'type'],
            'zero amount' => [['amount' => '0'], 'amount'],
            'cashback needs a payment mode' => [['payment_mode' => null], 'payment_mode'],
            'upi needs the app' => [['upi_platform' => null], 'upi_platform'],
            'upi needs the id' => [['payee_upi' => ''], 'payee_upi'],
            'neft needs an account' => [['payment_mode' => 'neft', 'payee_ifsc' => 'HDFC0001234'], 'payee_account_no'],
            'neft needs a valid ifsc' => [['payment_mode' => 'neft', 'payee_account_no' => '123456789', 'payee_ifsc' => 'HDFC1234'], 'payee_ifsc'],
        ];
    }

    public function test_an_expected_cashback_needs_no_payment_details_yet(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, [
            'type' => 'expected',
            'amount' => '3000',
        ]);

        $this->assertSame(CashbackType::Expected, $form->type);
        $this->assertNull($form->payment_mode);
        $this->assertSame('expected', $this->customer->fresh()->cashback_option);
    }

    public function test_rejecting_drops_the_files_cashback_and_tells_the_requester(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm());

        try {
            $this->cashback->reject($form, $this->managerUser, '  ');
            $this->fail('A rejection needs a reason.');
        } catch (ValidationException) {
        }

        $this->cashback->reject($form, $this->managerUser, 'No cashback on this scheme.');

        $this->assertSame(CashbackRequestStatus::Rejected, $form->fresh()->status);
        $this->assertSame(0.0, (float) $this->customer->fresh()->cashback);
        $this->assertSame(1, $this->teamLeaderUser->notifications()->count());
        $this->assertTrue($this->cashback->canRaiseOn($this->teamLeaderUser, $this->customer->fresh()));
    }

    public function test_an_unpaid_expected_cashback_is_retrieved_by_the_team_leader_and_the_achievement_recovers_it(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, ['type' => 'expected', 'amount' => '8000']);
        $this->cashback->approve($form, $this->managerUser);
        $this->cashback->approve($form->fresh(), $this->adminUser);

        $calculator = new AchievementCalculatorService;
        $this->assertSame(8000.0, $calculator->getPerformance($this->caller)['cashback']);

        $this->assertFalse($this->cashback->canRetrieve($this->callerUser, $form->fresh()));
        $this->assertTrue($this->cashback->canRetrieve($this->teamLeaderUser, $form->fresh()));

        $this->cashback->retrieve($form->fresh(), $this->teamLeaderUser, 'Customer did not need it.');

        $form->refresh();
        $this->assertSame(CashbackRequestStatus::Retrieved, $form->status);
        $this->assertSame($this->teamLeaderUser->id, $form->retrieved_by);
        $this->assertSame(0.0, (float) $this->customer->fresh()->cashback);
        $this->assertSame(0.0, (new AchievementCalculatorService)->getPerformance($this->caller)['cashback']);
        $this->assertSame(1, $this->accountsUser->notifications()->where('data->title', 'like', 'Expected cashback retrieved%')->count());
    }

    public function test_only_expected_cashback_can_be_retrieved(): void
    {
        $form = $this->cashback->raise($this->adminUser, $this->customer, $this->upiForm());

        $this->assertFalse($this->cashback->canRetrieve($this->teamLeaderUser, $form));

        $this->expectException(AuthorizationException::class);
        $this->cashback->retrieve($form, $this->teamLeaderUser, 'Not needed.');
    }

    public function test_a_paid_expected_cashback_cannot_be_retrieved(): void
    {
        $form = $this->cashback->raise($this->adminUser, $this->customer, ['type' => 'expected', 'amount' => '1000']);

        $this->cashback->updatePayment($form, $this->accountsUser, [
            'payment_status' => 'paid',
            'payment_mode' => 'cash',
            'payment_date' => today()->toDateString(),
        ]);

        $this->assertFalse($this->cashback->canRetrieve($this->teamLeaderUser, $form->fresh()));

        $this->expectException(AuthorizationException::class);
        $this->cashback->retrieve($form->fresh(), $this->teamLeaderUser, 'Not needed.');
    }

    public function test_only_the_admin_changes_an_approved_amount_and_the_change_is_logged(): void
    {
        $form = $this->cashback->raise($this->adminUser, $this->customer, $this->upiForm(['amount' => '5000']));

        $this->assertFalse($this->cashback->canChangeAmount($this->managerUser, $form));
        $this->assertFalse($this->cashback->canChangeAmount($this->accountsUser, $form));

        try {
            $this->cashback->changeAmount($form, $this->managerUser, '1000', 'Lower.');
            $this->fail('Only the Admin may change an approved amount.');
        } catch (AuthorizationException) {
        }

        $this->cashback->changeAmount($form, $this->adminUser, '3,500', 'Bank cut the payout.');

        $this->assertSame(3500.0, (float) $form->fresh()->approved_amount);
        $this->assertSame(5000.0, (float) $form->fresh()->amount);
        $this->assertSame(3500.0, (float) $this->customer->fresh()->cashback);

        $log = Activity::query()->where('description', 'Cashback amount changed by the Admin')->sole();
        $this->assertSame('Bank cut the payout.', $log->properties['reason']);
        $this->assertSame(5000, (int) $log->properties['old_amount']);
        $this->assertSame(3500, (int) $log->properties['new_amount']);
    }

    public function test_accounts_record_the_payment_with_its_utr_and_on_hold_needs_a_reason(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm());

        $this->assertFalse($this->cashback->canUpdatePayment($this->accountsUser, $form));

        $this->cashback->approve($form, $this->managerUser);
        $this->cashback->approve($form->fresh(), $this->adminUser);
        $form->refresh();

        $this->assertFalse($this->cashback->canUpdatePayment($this->managerUser, $form));
        $this->assertTrue($this->cashback->canUpdatePayment($this->accountsUser, $form));

        try {
            $this->cashback->updatePayment($form, $this->accountsUser, ['payment_status' => 'on_hold']);
            $this->fail('On hold needs a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_remarks', $exception->errors());
        }

        try {
            $this->cashback->updatePayment($form, $this->accountsUser, [
                'payment_status' => 'paid',
                'payment_mode' => 'upi',
                'upi_platform' => 'phonepe',
                'payee_upi' => 'ravi@ybl',
                'payment_date' => today()->toDateString(),
            ]);
            $this->fail('A UPI payment needs its UTR.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transaction_id', $exception->errors());
        }

        $this->cashback->updatePayment($form, $this->accountsUser, [
            'payment_status' => 'paid',
            'payment_mode' => 'upi',
            'upi_platform' => 'phonepe',
            'payee_upi' => 'ravi@ybl',
            'transaction_id' => 'UTR123456789',
            'payment_date' => today()->toDateString(),
            'payment_remarks' => 'Paid from current account.',
        ]);

        $form->refresh();
        $this->assertSame(CashbackPaymentStatus::Paid, $form->payment_status);
        $this->assertSame('UTR123456789', $form->transaction_id);
        $this->assertSame('phonepe', $form->upi_platform);
        $this->assertSame($this->accountsUser->id, $form->paid_by);
        $this->assertSame(today()->toDateString(), $form->payment_date->toDateString());
        $this->assertSame(1, $this->teamLeaderUser->notifications()->where('data->title', 'like', '%marked Paid%')->count());
    }

    public function test_finalizing_disbursal_needs_a_declaration_and_a_form_for_declared_cashback(): void
    {
        $base = [
            'disbursal_status' => 'disbursed',
            'disbursal_date' => today()->toDateString(),
            'sanctioned_loan_amount' => '500000',
            'subvention' => '0',
            'docking' => '0',
            'sanctioned_remarks' => 'ok',
        ];

        foreach ([null => 'cashback_option', 'cashback' => 'cashback_option', 'expected' => 'cashback_option'] as $option => $key) {
            try {
                CustomerJourneyService::sanction($this->customer->fresh(), $base + ['cashback_option' => $option ?: null]);
                $this->fail('Finalizing must be refused for declaration '.var_export($option, true));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
            }
        }

        $this->assertSame('approved', $this->customer->fresh()->journey_status);

        $this->cashback->raise($this->teamLeaderUser, $this->customer, ['type' => 'expected', 'amount' => '2500']);

        try {
            CustomerJourneyService::sanction($this->customer->fresh(), $base + ['cashback_option' => 'none']);
            $this->fail('A file with a live form cannot be declared as no cashback.');
        } catch (ValidationException) {
        }

        $customer = CustomerJourneyService::sanction($this->customer->fresh(), $base + ['cashback_option' => 'expected']);

        $this->assertSame('sanctioned', $customer->journey_status);
        $this->assertSame(2500.0, (float) $customer->cashback);
        $this->assertSame('expected', $customer->cashback_option);
    }

    public function test_no_cashback_finalizes_with_zero(): void
    {
        $customer = CustomerJourneyService::sanction($this->customer, [
            'disbursal_status' => 'disbursed',
            'disbursal_date' => today()->toDateString(),
            'cashback_option' => 'none',
            'sanctioned_remarks' => 'ok',
        ]);

        $this->assertSame(0.0, (float) $customer->cashback);
        $this->assertSame('none', $customer->cashback_option);
    }

    public function test_an_edit_request_cannot_change_cashback_on_a_file_with_a_cashback_form(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm());
        $this->cashback->reject($form, $this->managerUser, 'Wrong amount.');

        $this->expectException(ValidationException::class);
        app(CustomerEditRequestService::class)->raise($this->teamLeaderUser, $this->customer->fresh(), 'step_4_disbursal', 'Wrong cashback.', [
            ['field' => 'cashback', 'value' => '100'],
        ]);
    }

    public function test_the_create_page_fills_the_form_from_the_listing(): void
    {
        $this->actingAs($this->teamLeaderUser);

        Livewire::test(CreateCustomerCashbackRequest::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSee('ABCDE1234F')
            ->fillForm([
                'type' => 'cashback',
                'amount' => '12500',
                'payment_mode' => 'neft',
            ])
            ->fillForm([
                'payee_account_no' => '001234567890',
                'payee_ifsc' => 'hdfc0001234',
                'remarks' => 'Promised at login.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $form = CustomerCashbackRequest::query()->sole();
        $this->assertSame(12500.0, (float) $form->amount);
        $this->assertSame('HDFC0001234', $form->payee_ifsc);
        $this->assertSame($this->teamLeaderUser->id, $form->requested_by);
    }

    public function test_the_listing_voucher_and_actions_for_each_role(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['amount' => '5000']));

        $this->actingAs($this->outsiderUser);
        $this->assertSame([], CustomerCashbackRequestResource::getEloquentQuery()->pluck('id')->all());

        $this->actingAs($this->teamLeaderUser);
        Livewire::test(ListCustomerCashbackRequests::class)
            ->assertCanSeeTableRecords([$form])
            ->assertActionHidden(TestAction::make('approveCashback')->table($form));

        $this->actingAs($this->managerUser);
        Livewire::test(ListCustomerCashbackRequests::class)
            ->callAction(TestAction::make('approveCashback')->table($form), ['note' => 'Fine'])
            ->assertNotified('Approved — sent to the Admin');

        $this->actingAs($this->adminUser);
        Livewire::test(ViewCustomerCashbackRequest::class, ['record' => $form->id])
            ->assertOk()
            ->assertSee('Acknowledgment')
            ->assertSee($form->voucher_no)
            ->assertSee('Ravi Sharma')
            ->assertSee('Tarun Lead')
            ->callAction('approveCashback', ['approved_amount' => '5000'])
            ->assertNotified('Cashback approved');

        $this->actingAs($this->accountsUser);
        Livewire::test(ViewCustomerCashbackRequest::class, ['record' => $form->id])
            ->assertActionHidden('changeCashbackAmount')
            ->callAction(TestAction::make('updateCashbackPayment')->schemaComponent('cashbackVoucher', schema: 'infolist'), [
                'payment_status' => 'paid',
                'payment_mode' => 'upi',
                'upi_platform' => 'gpay',
                'payee_upi' => '9876543210@okaxis',
                'transaction_id' => 'UTR0001',
                'payment_date' => today()->toDateString(),
                'paid_by_name' => 'Suresh Accounts',
            ])
            ->assertNotified('Payment details saved');

        $this->assertSame(CashbackPaymentStatus::Paid, $form->fresh()->payment_status);
        $this->assertSame('Suresh Accounts', $form->fresh()->paid_by_name);
    }

    public function test_a_team_leaders_form_waits_24_hours_for_their_supervisor_then_moves_to_the_admin(): void
    {
        $this->travelTo(now()->setDateTime(2026, 11, 21, 10, 0));

        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm());

        $this->assertSame(CashbackRequestStatus::PendingManager, $form->status);
        $this->assertSame($this->manager->id, $form->supervisor_employee_id);
        $this->assertSame('2026-11-22 10:00', $form->supervisor_due_at->format('Y-m-d H:i'));
        $this->assertStringContainsString('within 24 hours', $this->managerUser->notifications()->sole()->data['body']);

        $this->travelTo(now()->setDateTime(2026, 11, 22, 9, 59));
        $this->artisan('cashback:escalate-overdue')->expectsOutput('0 cashback form(s) moved to the Admin.')->assertSuccessful();
        $this->assertTrue($this->cashback->canApprove($this->managerUser, $form->fresh()));

        $this->travelTo(now()->setDateTime(2026, 11, 22, 10, 1));
        $this->assertFalse($this->cashback->canApprove($this->managerUser, $form->fresh()));

        try {
            $this->cashback->approve($form->fresh(), $this->managerUser);
            $this->fail('The supervisor cannot approve after the TAT.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('24-hour approval time is over', $exception->getMessage());
        }

        $this->artisan('cashback:escalate-overdue')->expectsOutput('1 cashback form(s) moved to the Admin.')->assertSuccessful();

        $form->refresh();
        $this->assertSame(CashbackRequestStatus::PendingAdmin, $form->status);
        $this->assertSame('2026-11-22 10:01', $form->escalated_at->format('Y-m-d H:i'));
        $this->assertNull($form->manager_approved_by);
        $this->assertSame(1, $this->adminUser->notifications()->where('data->body', 'like', '%not approved by Meena Floor within 24 hours%')->count());
        $this->assertSame(1, $this->managerUser->notifications()->where('data->title', 'like', 'Cashback form moved to the Admin%')->count());
        $this->assertSame(1, $this->teamLeaderUser->notifications()->where('data->title', 'like', 'Cashback form moved to the Admin%')->count());

        $this->artisan('cashback:escalate-overdue')->expectsOutput('0 cashback form(s) moved to the Admin.');

        $this->cashback->approve($form, $this->adminUser);
        $this->assertSame(CashbackRequestStatus::Approved, $form->fresh()->status);

        $this->actingAs($this->adminUser);
        Livewire::test(ViewCustomerCashbackRequest::class, ['record' => $form->id])
            ->assertSee('Not signed within 24 h — moved to Admin 22/11/2026, 10:01 AM')
            ->assertSee('Not approved within 24 hours — moved to the Admin');
    }

    public function test_the_supervisor_or_anyone_above_them_approves_never_an_outsider_or_the_filler(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm());

        $this->assertTrue($this->cashback->canApprove($this->managerUser, $form));
        $this->assertTrue($this->cashback->canApprove($this->clusterManagerUser, $form));
        $this->assertFalse($this->cashback->canApprove($this->outsiderUser, $form));
        $this->assertFalse($this->cashback->canApprove($this->teamLeaderUser, $form));

        $this->cashback->approve($form, $this->clusterManagerUser, 'Signed for Meena.');
        $this->assertSame(CashbackRequestStatus::PendingAdmin, $form->fresh()->status);
        $this->assertSame($this->clusterManagerUser->id, $form->fresh()->manager_approved_by);
    }

    public function test_a_team_leader_whose_supervisor_has_no_login_goes_straight_to_the_admin(): void
    {
        $this->managerUser->update(['is_active' => false]);
        $this->clusterManagerUser->update(['is_active' => false]);

        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm());

        $this->assertSame(CashbackRequestStatus::PendingAdmin, $form->status);
        $this->assertNull($form->supervisor_employee_id);
        $this->assertNull($form->supervisor_due_at);
        $this->assertSame(1, $this->adminUser->notifications()->count());
    }

    public function test_the_escalation_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_ends_with((string) $event->command, 'cashback:escalate-overdue'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    public function test_the_voucher_names_manager_and_cluster_manager_and_the_admin_records_the_payment(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['amount' => '5000']));
        $this->assertSame($this->clusterManager->id, $form->cluster_manager_employee_id);

        $this->cashback->approve($form, $this->managerUser);
        $this->cashback->approve($form->fresh(), $this->adminUser);

        $this->actingAs($this->adminUser);

        Livewire::test(ViewCustomerCashbackRequest::class, ['record' => $form->id])
            ->assertSee('Manager Name')
            ->assertSee('Meena Floor')
            ->assertSee('Cluster Manager Name')
            ->assertSee('Kavita Cluster')
            ->assertDontSee('Floor Manager Name')
            ->assertSee('Filled &amp; signed', escape: false)
            ->assertSee('To be filled by Admin / Accounts')
            ->callAction(TestAction::make('updateCashbackPayment')->schemaComponent('cashbackVoucher', schema: 'infolist'), [
                'payment_status' => 'paid',
                'payment_mode' => 'neft',
                'payee_account_no' => '001234567890',
                'payee_ifsc' => 'HDFC0001234',
                'transaction_id' => 'UTR555',
                'payment_date' => today()->toDateString(),
                'paid_by_name' => 'Ramesh (Cashier)',
            ])
            ->assertNotified('Payment details saved')
            ->assertSee('UTR555')
            ->assertSee('Ramesh (Cashier)')
            ->assertDontSee('To be filled by Admin / Accounts');

        $form->refresh();
        $this->assertSame('Ramesh (Cashier)', $form->paid_by_name);
        $this->assertSame($this->adminUser->id, $form->paid_by);
    }

    public function test_a_paid_cashback_is_revised_for_a_second_payment_through_the_same_approval(): void
    {
        $original = $this->paidForm('5000', 'UTR-FIRST');

        $this->assertTrue($this->cashback->canRevise($this->teamLeaderUser, $original));

        try {
            $this->cashback->revise($this->teamLeaderUser, $original, $this->upiForm(['amount' => '2000', 'remarks' => '']));
            $this->fail('A revision needs a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('remarks', $exception->errors());
        }

        $revision = $this->cashback->revise($this->teamLeaderUser, $original, $this->upiForm(['amount' => '2000', 'remarks' => 'Second tranche promised.']));

        $this->assertSame($original->voucher_no.'/R1', $revision->voucher_no);
        $this->assertSame($original->id, $revision->revision_of_id);
        $this->assertSame(1, $revision->revision_no);
        $this->assertSame(CashbackType::Cashback, $revision->type);
        $this->assertSame(CashbackRequestStatus::PendingManager, $revision->status);
        $this->assertSame($this->manager->id, $revision->supervisor_employee_id);
        $this->assertSame(7000.0, (float) $this->customer->fresh()->cashback);

        // Nothing else can be raised on the case while the revision waits.
        $this->assertFalse($this->cashback->canRevise($this->teamLeaderUser, $original->fresh()));

        $this->cashback->approve($revision, $this->managerUser);
        $this->cashback->approve($revision->fresh(), $this->adminUser);

        try {
            $this->cashback->updatePayment($revision->fresh(), $this->accountsUser, $this->paidWith('UTR-FIRST'));
            $this->fail('The second payment must have its own UTR.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($original->voucher_no, $exception->errors()['transaction_id'][0]);
        }

        $this->cashback->updatePayment($revision->fresh(), $this->accountsUser, $this->paidWith('UTR-SECOND'));

        $this->assertTrue($revision->fresh()->isPaid());
        $this->assertSame(7000.0, (float) $this->customer->fresh()->cashback);
        $this->assertFalse($this->cashback->canRevise($this->teamLeaderUser, $revision->fresh()));
        $this->assertTrue($this->cashback->canRevise($this->teamLeaderUser, $original->fresh()));
        $this->assertSame($original->voucher_no.'/R2', $this->cashback->revise($this->managerUser, $original->fresh(), $this->upiForm(['amount' => '500', 'remarks' => 'Third.']))->voucher_no);

        $this->actingAs($this->adminUser);
        Livewire::test(ViewCustomerCashbackRequest::class, ['record' => $original->id])
            ->assertSee('Payments on this cashback')
            ->assertSee('UTR-FIRST')
            ->assertSee('UTR-SECOND')
            ->assertSee($original->voucher_no.'/R1');
    }

    public function test_only_a_paid_original_can_be_revised_and_the_voucher_page_offers_it(): void
    {
        $form = $this->cashback->raise($this->adminUser, $this->customer, $this->upiForm());

        $this->assertFalse($this->cashback->canRevise($this->teamLeaderUser, $form));

        try {
            $this->cashback->revise($this->teamLeaderUser, $form, $this->upiForm(['remarks' => 'Again.']));
            $this->fail('An unpaid form cannot be revised.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('Only a paid cashback', $exception->getMessage());
        }

        $this->cashback->updatePayment($form, $this->accountsUser, $this->paidWith('UTR-A'));

        $this->assertFalse($this->cashback->canRevise($this->callerUser, $form->fresh()));

        $this->actingAs($this->teamLeaderUser);
        Livewire::test(ViewCustomerCashbackRequest::class, ['record' => $form->id])
            ->callAction('reviseCashback', [
                'amount' => '1500',
                'payment_mode' => 'cash',
                'remarks' => 'Customer was short-paid.',
            ])
            ->assertNotified('Revision '.$form->voucher_no.'/R1 submitted for approval');

        $this->assertSame(1500.0, (float) $form->revisions()->sole()->amount);
    }

    public function test_the_customer_page_fills_a_form_and_approves_the_one_waiting(): void
    {
        $this->actingAs($this->teamLeaderUser);

        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->assertSee('Cashback')
            ->callAction(TestAction::make('fillCashbackForm')->schemaComponent('customerCashback'), [
                'type' => 'expected',
                'amount' => '4000',
            ])
            ->assertHasNoActionErrors();

        $form = CustomerCashbackRequest::query()->sole();
        $this->assertSame(CashbackType::Expected, $form->type);
        $this->assertSame(4000.0, (float) $this->customer->fresh()->cashback);

        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->assertActionDoesNotExist(TestAction::make('fillCashbackForm')->schemaComponent('customerCashback'))
            ->assertActionDoesNotExist(TestAction::make('approveCashback')->schemaComponent('customerCashback'));

        $this->actingAs($this->managerUser);

        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->callAction(TestAction::make('approveCashback')->schemaComponent('customerCashback'), ['note' => 'OK'])
            ->assertNotified('Approved — sent to the Admin');

        $this->assertSame(CashbackRequestStatus::PendingAdmin, $form->fresh()->status);

        $this->actingAs($this->teamLeaderUser);

        Livewire::test(ViewCustomerCashbackRequest::class, ['record' => $form->id])
            ->callAction('retrieveCashback', ['retrieval_reason' => 'Not needed after all.'])
            ->assertNotified('Expected cashback retrieved');

        $this->assertSame(CashbackRequestStatus::Retrieved, $form->fresh()->status);
        $this->assertSame(0.0, (float) $this->customer->fresh()->cashback);
    }

    public function test_a_new_cashback_on_a_case_punched_in_months_ago_adds_to_the_cashback_it_already_had(): void
    {
        $oldCase = $this->punchedInCase('Kiran Old Case', '2026-08-14', cashback: 2000);
        $this->assertNull($oldCase->cashback_option);

        $first = $this->cashback->raise($this->teamLeaderUser, $oldCase, $this->upiForm(['amount' => '1000']));

        $oldCase->refresh();
        $this->assertSame(2000.0, (float) $oldCase->cashback_before_forms);
        $this->assertSame(3000.0, (float) $oldCase->cashback);
        $this->assertSame('cashback', $oldCase->cashback_option);
        $this->assertSame('sanctioned', $oldCase->journey_status);

        // Another new cashback once the first is through: both count.
        $this->cashback->approve($first, $this->adminUser);
        $second = $this->cashback->raise($this->managerUser, $oldCase->fresh(), ['type' => 'expected', 'amount' => '500']);
        $this->assertSame(3500.0, (float) $oldCase->fresh()->cashback);

        // Retrieving or rejecting one form takes off only that form's amount.
        $this->cashback->retrieve($second, $this->teamLeaderUser, 'Not needed.');
        $this->assertSame(3000.0, (float) $oldCase->fresh()->cashback);

        $this->cashback->changeAmount($first->fresh(), $this->adminUser, '1500', 'Revised.');
        $this->assertSame(3500.0, (float) $oldCase->fresh()->cashback);

        $this->assertSame(3500.0, (new AchievementCalculatorService)->getPerformance($this->caller, Carbon::parse('2026-08-01'))['cashback']);
    }

    public function test_the_separate_form_lists_earlier_cases_by_disbursal_month_and_fills_one(): void
    {
        $august = $this->punchedInCase('Kiran Old Case', '2026-08-14', cashback: 0);
        $september = $this->punchedInCase('Sunil Sept Case', '2026-09-03', cashback: 0);
        $waiting = $this->punchedInCase('Waiting Case', '2026-08-20', cashback: 0);
        $this->cashback->raise($this->teamLeaderUser, $waiting, $this->upiForm());

        $this->actingAs($this->teamLeaderUser);

        $page = Livewire::test(CreateCustomerCashbackRequest::class)
            ->assertSee('Kiran Old Case')
            ->assertSee('Sunil Sept Case')
            ->assertDontSee('Waiting Case')
            ->fillForm(['disbursal_month' => '2026-08'])
            ->assertSee('Kiran Old Case')
            ->assertSee('disbursed 14 Aug 2026')
            ->assertDontSee('Sunil Sept Case');

        $page->fillForm(['customer_id' => $august->id])
            ->assertSee('this form adds to it')
            ->fillForm(['type' => 'cashback', 'amount' => '750', 'payment_mode' => 'cash'])
            ->call('create')
            ->assertHasNoFormErrors();

        $form = CustomerCashbackRequest::query()->where('customer_id', $august->id)->sole();
        $this->assertSame(750.0, (float) $form->amount);
        $this->assertSame(750.0, (float) $august->fresh()->cashback);
        $this->assertSame(0, $september->fresh()->cashbackRequests()->count());
    }

    public function test_the_customers_list_has_a_cashback_form_button_for_team_leaders_and_up(): void
    {
        $this->actingAs($this->teamLeaderUser);
        Livewire::test(ListCustomers::class)->assertActionVisible('fillCashbackForm');

        $this->actingAs($this->callerUser);
        Livewire::test(ListCustomers::class)->assertActionHidden('fillCashbackForm');
    }

    public function test_step_4_blocks_finalizing_until_the_declared_form_is_filled(): void
    {
        $this->actingAs($this->managerUser);

        $finalize = TestAction::make('finalize_disbursal')->schemaComponent('disbursal_actions', schema: 'form');
        $fillForm = TestAction::make('fillCashbackFormStep4')->schemaComponent('cashback', schema: 'form');
        $step4 = [
            'disbursal_status' => 'disbursed',
            'disbursal_date' => today()->toDateString(),
            'channel' => 'ruloans',
            'sanctioned_loan_amount' => '500000',
            'subvention' => '0',
            'docking' => '0',
            'sanctioned_remarks' => 'Disbursed.',
            'cashback_option' => 'cashback',
        ];

        Livewire::test(EditCustomer::class, ['record' => $this->customer->id])
            ->fillForm($step4)
            ->callAction($finalize)
            ->assertNotified('Fill the cashback form for this file before going ahead.');

        $this->assertSame('approved', $this->customer->fresh()->journey_status);

        Livewire::test(EditCustomer::class, ['record' => $this->customer->id])
            ->fillForm($step4)
            ->callAction($fillForm, [
                'amount' => '6000',
                'payment_mode' => 'cash',
            ])
            ->assertHasNoActionErrors()
            ->assertSchemaStateSet(['cashback' => '6,000'], 'form')
            ->callAction($finalize);

        $customer = $this->customer->fresh();
        $this->assertSame('sanctioned', $customer->journey_status);
        $this->assertSame(6000.0, (float) $customer->cashback);
        $this->assertSame('cashback', $customer->cashback_option);
        $this->assertSame(CashbackRequestStatus::PendingAdmin, CustomerCashbackRequest::query()->sole()->status);
    }

    public function test_the_customers_menu_shows_the_submodule(): void
    {
        $this->actingAs($this->teamLeaderUser)
            ->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee(CustomerCashbackRequestResource::getUrl('index'), escape: false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
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

    /** A Team Leader's cashback, approved by the Manager and the Admin, paid with $utr. */
    private function paidForm(string $amount, string $utr): CustomerCashbackRequest
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->customer, $this->upiForm(['amount' => $amount]));
        $this->cashback->approve($form, $this->managerUser);
        $this->cashback->approve($form->fresh(), $this->adminUser);

        return $this->cashback->updatePayment($form->fresh(), $this->accountsUser, $this->paidWith($utr));
    }

    /**
     * @return array<string, string>
     */
    private function paidWith(string $utr): array
    {
        return [
            'payment_status' => 'paid',
            'payment_mode' => 'upi',
            'upi_platform' => 'gpay',
            'payee_upi' => '9876543210@okaxis',
            'transaction_id' => $utr,
            'payment_date' => today()->toDateString(),
        ];
    }

    private function punchedInCase(string $name, string $disbursedOn, float $cashback): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
            'customer_name' => $name,
            'journey_status' => 'sanctioned',
            'disbursal_status' => 'disbursed',
            'disbursal_finalized' => true,
            'disbursal_date' => $disbursedOn,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => 300000,
            'cashback' => $cashback,
            'cashback_option' => null,
            'subvention' => 0,
            'docking' => 0,
        ]);
    }

    private function userFor(Employee $employee, string $role): User
    {
        $user = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
