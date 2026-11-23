<?php

namespace Tests\Feature;

use App\Enums\CashbackRequestStatus;
use App\Filament\Pages\CashbackAnalysis;
use App\Filament\Resources\CustomerCashbackRequests\Pages\ViewCustomerCashbackRequest;
use App\Filament\Resources\CustomerCashbackRequests\Schemas\CustomerCashbackRequestInfolist;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\CashbackAnalysisService;
use App\Services\CustomerCashbackService;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Customers → Cashback Analysis: an employee's (or the company's) month —
 * cases punched in (disbursed), cases with cashback, cashback, disbursal and
 * approval amounts, cashback % — split by team member; the same analysis of
 * the case's chain saved on every cashback form when it is raised and shown
 * to whoever approves it; and the month's requests kept on the page.
 */
class CashbackAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private Employee $clusterManager;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $asha;

    private Employee $ravi;

    private Employee $outsider;

    private User $teamLeaderUser;

    private User $managerUser;

    private User $adminUser;

    private User $accountsUser;

    private User $outsiderUser;

    private CustomerCashbackService $cashback;

    private CashbackAnalysisService $analysis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-21 12:00:00'));

        foreach (['Admin', 'Accounts', 'Cluster Manager', 'Manager', 'Team Leader', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $onRolls = ['reporting_date' => '2026-01-01', 'doj' => '2026-01-01'];

        $this->clusterManager = Employee::factory()->create([...$onRolls, 'designation' => Employee::DESIGNATION_CLUSTER, 'emp_name' => 'Kavita Cluster']);
        $this->manager = Employee::factory()->create([...$onRolls, 'designation' => Employee::DESIGNATION_MANAGER, 'cluster_id' => $this->clusterManager->id, 'emp_name' => 'Meena Floor']);
        $this->teamLeader = Employee::factory()->create([...$onRolls, 'designation' => Employee::DESIGNATION_TEAM_LEADER, 'manager_id' => $this->manager->id, 'emp_name' => 'Tarun Lead']);
        $this->asha = Employee::factory()->create([...$onRolls, 'designation' => Employee::DESIGNATION_CALLER, 'superviser_id' => $this->teamLeader->id, 'manager_id' => $this->manager->id, 'emp_name' => 'Asha Caller']);
        $this->ravi = Employee::factory()->create([...$onRolls, 'designation' => Employee::DESIGNATION_CALLER, 'superviser_id' => $this->teamLeader->id, 'manager_id' => $this->manager->id, 'emp_name' => 'Ravi Caller']);
        $this->outsider = Employee::factory()->create([...$onRolls, 'designation' => Employee::DESIGNATION_MANAGER, 'emp_name' => 'Omar Outside']);

        $this->teamLeaderUser = $this->userFor($this->teamLeader, 'Team Leader');
        $this->managerUser = $this->userFor($this->manager, 'Manager');
        $this->outsiderUser = $this->userFor($this->outsider, 'Manager');
        $this->adminUser = User::factory()->create(['is_active' => true]);
        $this->adminUser->assignRole('Admin');
        $this->accountsUser = User::factory()->create(['is_active' => true]);
        $this->accountsUser->assignRole('Accounts');

        $this->cashback = app(CustomerCashbackService::class);
        $this->analysis = app(CashbackAnalysisService::class);
    }

    public function test_a_callers_month_counts_cases_punched_in_cashback_disbursal_and_approval(): void
    {
        $this->disbursedCase($this->asha, 1000000, '2026-11-05', cashback: 10000, subvention: 5000);
        $this->disbursedCase($this->asha, 500000, '2026-11-10', subvention: 2500);
        $this->disbursedCase($this->asha, 800000, '2026-10-20', cashback: 5000, subvention: 9000);
        $this->approvedCase($this->asha, 300000, '2026-11-15');

        $figures = $this->analysis->figures($this->asha, $this->november());

        $this->assertSame(2, $figures['cases_punched_in']);
        $this->assertSame(1, $figures['cases_with_cashback']);
        $this->assertSame(50.0, $figures['cases_with_cashback_percent']);
        $this->assertSame(10000.0, $figures['cashback_amount']);
        $this->assertSame(1500000.0, $figures['disbursal_amount']);
        $this->assertSame(3, $figures['approved_cases']);
        $this->assertSame(1800000.0, $figures['approval_amount']);
        $this->assertSame(0.67, $figures['cashback_percent']);
        $this->assertSame(10000.0, $figures['average_cashback']);
        $this->assertSame(2, $figures['cases_with_subvention']);
        $this->assertSame(100.0, $figures['cases_with_subvention_percent']);
        $this->assertSame(7500.0, $figures['subvention_amount']);
        $this->assertSame(0.5, $figures['subvention_percent']);
        $this->assertSame(3750.0, $figures['average_subvention']);
        $this->assertSame(17500.0, $figures['deductions_amount']);
        $this->assertSame(1.17, $figures['deductions_percent']);

        $october = $this->analysis->figures($this->asha, Carbon::parse('2026-10-01'));

        $this->assertSame(1, $october['cases_punched_in']);
        $this->assertSame(5000.0, $october['cashback_amount']);
        $this->assertSame(9000.0, $october['subvention_amount']);
    }

    public function test_the_mis_cashback_and_subvention_count_first_like_the_achievement_figures(): void
    {
        $case = $this->disbursedCase($this->asha, 1000000, '2026-11-05', cashback: 10000, subvention: 1000);
        $case->settlements()->create(['settlement_no' => 'STL-0001', 'version' => 1, 'mis_cashback' => 0, 'mis_subvention' => 4500, 'mis_disbursal_amount' => 900000]);

        $figures = $this->analysis->figures($this->asha, $this->november());

        $this->assertSame(1, $figures['cases_punched_in']);
        $this->assertSame(0, $figures['cases_with_cashback']);
        $this->assertSame(0.0, $figures['cashback_amount']);
        $this->assertSame(900000.0, $figures['disbursal_amount']);
        $this->assertSame(1, $figures['cases_with_subvention']);
        $this->assertSame(4500.0, $figures['subvention_amount']);
        $this->assertSame(0.5, $figures['subvention_percent']);
    }

    public function test_a_team_leaders_month_rolls_up_the_team_and_splits_by_member(): void
    {
        $this->disbursedCase($this->asha, 1000000, '2026-11-05', cashback: 10000);
        $this->disbursedCase($this->ravi, 2000000, '2026-11-06', cashback: 30000, subvention: 10000);
        $this->disbursedCase($this->teamLeader, 500000, '2026-11-07', subvention: 5000);

        $figures = $this->analysis->figures($this->teamLeader, $this->november());

        $this->assertSame(3, $figures['cases_punched_in']);
        $this->assertSame(2, $figures['cases_with_cashback']);
        $this->assertSame(40000.0, $figures['cashback_amount']);
        $this->assertSame(3500000.0, $figures['disbursal_amount']);
        $this->assertSame(1.14, $figures['cashback_percent']);

        $rows = $this->analysis->breakdown($this->teamLeader, $this->november(), $figures);

        $this->assertSame([$this->asha->id, $this->ravi->id, null], array_column($rows, 'employee_id'));
        $this->assertSame([1, 1, 1], array_column($rows, 'cases_punched_in'));
        $this->assertSame([10000.0, 30000.0, 0.0], array_column($rows, 'cashback_amount'));
        $this->assertSame([1.0, 1.5, 0.0], array_column($rows, 'cashback_percent'));
        $this->assertSame([0.0, 10000.0, 5000.0], array_column($rows, 'subvention_amount'));
        $this->assertSame([1.0, 2.0, 1.0], array_column($rows, 'deductions_percent'));
        $this->assertSame(15000.0, $figures['subvention_amount']);
        $this->assertSame('Tarun Lead — own cases', $rows[2]['name']);
        $this->assertSame(3500000.0, array_sum(array_column($rows, 'disbursal_amount')));
    }

    public function test_a_caller_has_no_team_split(): void
    {
        $this->disbursedCase($this->asha, 1000000, '2026-11-05', cashback: 10000);

        $figures = $this->analysis->figures($this->asha, $this->november());

        $this->assertSame([], $this->analysis->breakdown($this->asha, $this->november(), $figures));
    }

    public function test_the_whole_company_is_every_case_disbursed_in_the_month_split_by_branch(): void
    {
        $this->disbursedCase($this->asha, 1000000, '2026-11-05', cashback: 10000);
        $this->disbursedCase($this->outsider, 400000, '2026-11-08', cashback: 4000);

        $figures = $this->analysis->figures(null, $this->november());

        $this->assertSame(2, $figures['cases_punched_in']);
        $this->assertSame(14000.0, $figures['cashback_amount']);
        $this->assertSame(1.0, $figures['cashback_percent']);

        $rows = $this->analysis->breakdown(null, $this->november(), $figures);

        $this->assertSame([$this->clusterManager->id, null], array_column($rows, 'employee_id'));
        $this->assertSame([10000.0, 4000.0], array_column($rows, 'cashback_amount'));
        $this->assertSame('Outside these branches', $rows[1]['name']);
    }

    public function test_raising_a_form_saves_the_chains_analysis_with_the_form_counted(): void
    {
        $case = $this->disbursedCase($this->asha, 1000000, '2026-10-20', subvention: 1000);

        $form = $this->cashback->raise($this->teamLeaderUser, $case, $this->upiForm(['amount' => '5,000', 'subvention_amount' => '3,000']));
        $snapshot = $form->fresh()->analysis_snapshot;

        $this->assertSame('2026-10', $snapshot['month']);
        $this->assertSame('disbursal', $snapshot['month_basis']);
        $this->assertSame($form->voucher_no, $snapshot['form']['voucher_no']);
        $this->assertSame(5000.0, (float) $snapshot['case']['cashback_amount']);
        $this->assertSame(0.5, (float) $snapshot['case']['cashback_percent']);
        $this->assertSame(3000.0, (float) $snapshot['form']['subvention_amount']);
        $this->assertSame(3000.0, (float) $snapshot['case']['subvention_amount']);
        $this->assertSame(0.3, (float) $snapshot['case']['subvention_percent']);
        $this->assertSame(0.8, (float) $snapshot['case']['deductions_percent']);
        $this->assertSame('2026-11-21 12:00:00', $snapshot['as_of']);

        $people = collect($snapshot['people'])->keyBy('employee_id');

        $this->assertSame('Case owner', $people[$this->asha->id]['role']);
        $this->assertSame('Team Leader · Filled by', $people[$this->teamLeader->id]['role']);
        $this->assertSame('Manager', $people[$this->manager->id]['role']);
        $this->assertSame(1, $people[$this->asha->id]['cases_punched_in']);
        $this->assertSame(5000.0, (float) $people[$this->asha->id]['cashback_amount']);
        $this->assertSame(0.5, (float) $people[$this->asha->id]['cashback_percent']);
        $this->assertSame(3000.0, (float) $people[$this->asha->id]['subvention_amount']);
        $this->assertSame(0.8, (float) $people[$this->asha->id]['deductions_percent']);
    }

    public function test_a_form_on_a_case_not_disbursed_yet_is_analysed_in_the_month_it_was_raised(): void
    {
        $case = $this->approvedCase($this->asha, 600000, '2026-11-18');

        $form = $this->cashback->raise($this->teamLeaderUser, $case, ['type' => 'expected', 'amount' => '2000']);
        $snapshot = $form->fresh()->analysis_snapshot;

        $this->assertSame('2026-11', $snapshot['month']);
        $this->assertSame('raised', $snapshot['month_basis']);

        $owner = collect($snapshot['people'])->firstWhere('employee_id', $this->asha->id);

        $this->assertSame(0, $owner['cases_punched_in']);
        $this->assertSame(1, $owner['approved_cases']);
        $this->assertSame(600000.0, (float) $owner['approval_amount']);
    }

    public function test_the_requests_summary_counts_the_months_forms_by_where_they_stand(): void
    {
        $pending = $this->cashback->raise($this->teamLeaderUser, $this->disbursedCase($this->asha, 1000000, '2026-11-05'), $this->upiForm(['amount' => '5000']));
        $approved = $this->cashback->raise($this->adminUser, $this->disbursedCase($this->ravi, 1000000, '2026-11-06'), $this->upiForm(['amount' => '3000']));
        $this->cashback->updatePayment($approved, $this->accountsUser, $this->paidWith('UTR0001'));
        $rejected = $this->cashback->raise($this->teamLeaderUser, $this->disbursedCase($this->asha, 1000000, '2026-11-07'), $this->upiForm(['amount' => '1000']));
        $this->cashback->reject($rejected, $this->managerUser, 'Not agreed');

        $summary = $this->analysis->requestSummary($this->teamLeader, $this->november());

        $this->assertSame(CashbackRequestStatus::PendingManager, $pending->status);
        $this->assertSame(3, $summary['raised']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(5000.0, $summary['pending_amount']);
        $this->assertSame(1, $summary['approved']);
        $this->assertSame(3000.0, $summary['approved_amount']);
        $this->assertSame(1, $summary['paid']);
        $this->assertSame(3000.0, $summary['paid_amount']);
        $this->assertSame(1, $summary['rejected']);

        $this->assertSame(0, $this->analysis->requestSummary($this->outsider, $this->november())['raised']);
        $this->assertSame(0, $this->analysis->requestSummary($this->teamLeader, Carbon::parse('2026-10-01'))['raised']);
    }

    public function test_the_page_shows_the_team_leaders_own_analysis_and_never_an_outsiders(): void
    {
        $this->disbursedCase($this->asha, 1000000, '2026-11-05', cashback: 10000, subvention: 2000);
        $this->disbursedCase($this->outsider, 400000, '2026-11-08', cashback: 4000);

        $this->actingAs($this->teamLeaderUser);

        Livewire::test(CashbackAnalysis::class)
            ->assertOk()
            ->assertSet('employeeId', (string) $this->teamLeader->id)
            ->assertSet('month', '2026-11')
            ->assertSee('Tarun Lead')
            ->assertSee('Cases punched in')
            ->assertSee('Cashback % of disbursal')
            ->assertSee('Cases with subvention')
            ->assertSee('Subvention % of disbursal')
            ->assertSee('Cashback + subvention % of disbursal')
            ->assertSee('Asha Caller')
            ->assertSee('1.00%')
            ->assertSee('0.20%')
            ->assertSee('1.20%')
            ->assertDontSee('Omar Outside')
            ->call('analyseEmployee', $this->outsider->id)
            ->assertSet('employeeId', (string) $this->teamLeader->id)
            ->call('analyseEmployee', $this->asha->id)
            ->assertSet('employeeId', (string) $this->asha->id)
            ->call('analyseMonth', '2026-10')
            ->assertSet('month', '2026-10');

        Livewire::withQueryParams(['employee' => (string) $this->outsider->id, 'month' => '2026-13'])
            ->test(CashbackAnalysis::class)
            ->assertSet('employeeId', (string) $this->teamLeader->id)
            ->assertSet('month', '2026-11')
            ->assertDontSee('Omar Outside');
    }

    public function test_the_admin_and_accounts_see_the_whole_company_and_can_pick_anyone(): void
    {
        $this->disbursedCase($this->outsider, 400000, '2026-11-08', cashback: 4000);

        foreach ([$this->adminUser, $this->accountsUser] as $user) {
            $this->actingAs($user);

            Livewire::test(CashbackAnalysis::class)
                ->assertOk()
                ->assertSet('employeeId', null)
                ->assertSee('Whole company')
                ->assertSee('By branch')
                ->call('analyseEmployee', $this->outsider->id)
                ->assertSet('employeeId', (string) $this->outsider->id)
                ->assertSee('Omar Outside')
                ->call('analyseEmployee', null)
                ->assertSet('employeeId', null);
        }
    }

    public function test_the_requests_list_keeps_the_months_forms_in_the_branch_with_their_saved_analysis(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->disbursedCase($this->asha, 1000000, '2026-10-20'), $this->upiForm());
        $outsiderForm = $this->cashback->raise($this->adminUser, $this->disbursedCase($this->outsider, 400000, '2026-11-08'), $this->upiForm());

        $this->actingAs($this->teamLeaderUser);

        Livewire::test(CashbackAnalysis::class)
            ->assertCanSeeTableRecords([$form])
            ->assertCanNotSeeTableRecords([$outsiderForm])
            ->assertSee('0.50%')
            ->mountAction(TestAction::make('analysis')->table($form))
            ->assertMountedActionModalSee(['When the form was raised', 'Team Leader · Filled by', 'Asha Caller'])
            ->call('analyseMonth', '2026-10')
            ->assertCanNotSeeTableRecords([$form]);

        $this->actingAs($this->adminUser);

        Livewire::test(CashbackAnalysis::class)
            ->assertCanSeeTableRecords([$form, $outsiderForm]);
    }

    public function test_the_supervisor_approves_from_the_analysis_page(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->disbursedCase($this->asha, 1000000, '2026-11-05'), $this->upiForm());

        $this->actingAs($this->managerUser);

        Livewire::test(CashbackAnalysis::class)
            ->callAction(TestAction::make('approveCashback')->table($form), ['note' => 'Within limits'])
            ->assertHasNoActionErrors();

        $this->assertSame(CashbackRequestStatus::PendingAdmin, $form->fresh()->status);
    }

    public function test_the_voucher_and_the_approve_pop_up_show_the_analysis(): void
    {
        $case = $this->disbursedCase($this->asha, 1000000, '2026-11-05');
        $form = $this->cashback->raise($this->teamLeaderUser, $case, $this->upiForm(['subvention_amount' => '2,000']));

        $this->actingAs($this->managerUser);

        Livewire::test(ViewCustomerCashbackRequest::class, ['record' => $form->id])
            ->assertOk()
            ->assertSee('Cashback analysis')
            ->assertSee('When the form was raised')
            ->assertSee('As on now')
            ->assertSee('Asha Caller')
            ->assertSee('subvention declared ₹2,000');

        Livewire::test(ViewCustomer::class, ['record' => $case->id])
            ->assertDontSee('Cases punched in')
            ->mountAction(TestAction::make('approveCashback')->schemaComponent('customerCashback'))
            ->assertMountedActionModalSee(['Cases punched in', 'As on now', 'Team Leader · Filled by', 'Subvention %', 'Cashback + subvention %', '0.70% together'])
            ->assertMountedActionModalDontSee('When the form was raised');
    }

    public function test_an_analysis_saved_before_subvention_was_analysed_still_shows(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->disbursedCase($this->asha, 1000000, '2026-11-05'), $this->upiForm());
        $this->cashback->approve($form, $this->managerUser);
        $this->cashback->approve($form->fresh(), $this->adminUser);

        $snapshot = $form->fresh()->analysis_snapshot;
        $legacyKeys = ['cases_with_subvention', 'cases_with_subvention_percent', 'subvention_amount', 'subvention_percent', 'average_subvention', 'deductions_amount', 'deductions_percent'];
        $snapshot['people'] = array_map(fn (array $person): array => array_diff_key($person, array_flip($legacyKeys)), $snapshot['people']);
        unset($snapshot['form']['subvention_amount'], $snapshot['case']['subvention_amount'], $snapshot['case']['subvention_percent'], $snapshot['case']['deductions_percent']);
        $form->forceFill(['analysis_snapshot' => $snapshot])->saveQuietly();

        $this->actingAs($this->adminUser);

        Livewire::test(ViewCustomerCashbackRequest::class, ['record' => $form->id])
            ->assertOk()
            ->assertSee('When the form was raised')
            ->assertDontSee('As on now')
            ->assertSee('Asha Caller');
    }

    public function test_a_form_raised_before_analyses_were_saved_shows_todays_figures(): void
    {
        $form = $this->cashback->raise($this->teamLeaderUser, $this->disbursedCase($this->asha, 1000000, '2026-11-05'), $this->upiForm());
        $form->forceFill(['analysis_snapshot' => null])->saveQuietly();

        $data = CustomerCashbackRequestInfolist::analysisViewData($form->fresh());

        $this->assertCount(1, $data['blocks']);
        $this->assertStringStartsWith('As on now', $data['blocks'][0]['title']);
        $this->assertNotNull($data['note']);
    }

    public function test_the_page_is_in_the_sidebar_for_the_cashback_audience_only(): void
    {
        $this->actingAs($this->teamLeaderUser);

        $this->followingRedirects()->get('/admin')->assertOk()->assertSee(CashbackAnalysis::getUrl());

        $this->assertTrue(CashbackAnalysis::canAccess());

        $this->actingAs(User::factory()->create(['is_active' => true]));

        $this->assertFalse(CashbackAnalysis::canAccess());
    }

    private function november(): Carbon
    {
        return Carbon::parse('2026-11-01');
    }

    private function disbursedCase(Employee $owner, float $amount, string $disbursedOn, float $cashback = 0, float $subvention = 0): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $owner->id,
            'employee_id' => $owner->id,
            'journey_status' => 'sanctioned',
            'disbursal_status' => 'disbursed',
            'disbursal_finalized' => true,
            'disbursal_date' => $disbursedOn,
            'approval_date' => $disbursedOn,
            'approved_loan_amount' => $amount,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => $amount,
            'cashback' => $cashback > 0 ? $cashback : null,
            'cashback_option' => null,
            'subvention' => $subvention,
            'docking' => 0,
        ]);
    }

    private function approvedCase(Employee $owner, float $amount, string $approvedOn): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $owner->id,
            'employee_id' => $owner->id,
            'journey_status' => 'approved',
            'approval_date' => $approvedOn,
            'approved_loan_amount' => $amount,
            'disbursal_date' => null,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => null,
            'cashback' => null,
            'subvention' => 0,
            'docking' => 0,
        ]);
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

    private function userFor(Employee $employee, string $role): User
    {
        $user = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
