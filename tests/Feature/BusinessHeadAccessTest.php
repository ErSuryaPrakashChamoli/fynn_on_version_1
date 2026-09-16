<?php

namespace Tests\Feature;

use App\Enums\JourneyModule;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\DailyCommitmentTeamView;
use App\Filament\Pages\EmployeeHierarchy;
use App\Filament\Pages\TeamPerformance;
use App\Filament\Resources\CustomerJourneyDelegations\CustomerJourneyDelegationResource;
use App\Filament\Resources\CustomerPanRequests\CustomerPanRequestResource;
use App\Filament\Resources\LeadAssignmentReports\LeadAssignmentReportResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Widgets\IncentiveStats;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\CustomerJourneyDelegation;
use App\Models\CustomerPanRequest;
use App\Models\CustomerSlaBreach;
use App\Models\Employee;
use App\Models\User;
use App\Services\DailyCommitmentService;
use App\Services\HierarchyRoleService;
use App\Services\Journey\CustomerJourneyDelegationService;
use App\Services\Journey\JourneySlaService;
use App\Services\ReportingLineService;
use App\Support\HierarchyHelper;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Business Head role opens the screens a Cluster Manager gets, but the
 * data behind them is always the Business Head's own branch — never the
 * whole company. The role follows the designation.
 *
 * Business Head BH ── Cluster Manager CM ── Manager M ── Team Leader TL ── Caller C
 * Business Head BH2 ── Manager M2 (skips Cluster Manager) ── Team Leader TL2 ── Caller C2
 */
class BusinessHeadAccessTest extends TestCase
{
    use RefreshDatabase;

    private Employee $businessHead;

    private Employee $cluster;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller;

    private Employee $otherBusinessHead;

    private Employee $otherManager;

    private Employee $otherTeamLeader;

    private Employee $otherCaller;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Business Head', 'Cluster Manager', 'Manager', 'Team Leader', 'Caller', 'MIS'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->businessHead = $this->employee(Employee::DESIGNATION_BUSINESS_HEAD);
        $this->cluster = $this->employee(Employee::DESIGNATION_CLUSTER, $this->businessHead);
        $this->manager = $this->employee(Employee::DESIGNATION_MANAGER, $this->cluster);
        $this->teamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        $this->caller = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeader);

        $this->otherBusinessHead = $this->employee(Employee::DESIGNATION_BUSINESS_HEAD);
        $this->otherManager = $this->employee(Employee::DESIGNATION_MANAGER, $this->otherBusinessHead);
        $this->otherTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->otherManager);
        $this->otherCaller = $this->employee(Employee::DESIGNATION_CALLER, $this->otherTeamLeader);
    }

    private function employee(int $designation, ?Employee $boss = null): Employee
    {
        return Employee::factory()->create([
            'designation' => $designation,
            'exit_status' => 'no',
            'reporting_date' => null,
            'category' => '2500000',
            ...app(ReportingLineService::class)->columnsUnder($boss?->id),
        ]);
    }

    private function loginFor(Employee $employee, string $role): User
    {
        return User::factory()->create(['employee_id' => $employee->id])->assignRole($role);
    }

    private function adminUser(): User
    {
        return User::factory()->create()->assignRole('Admin');
    }

    /*
    |--------------------------------------------------------------------------
    | The role follows the designation
    |--------------------------------------------------------------------------
    */

    public function test_promoting_an_employee_to_business_head_swaps_their_role(): void
    {
        $login = $this->loginFor($this->cluster, 'Cluster Manager');

        $this->cluster->update(['designation' => Employee::DESIGNATION_BUSINESS_HEAD, 'business_head_id' => null]);

        $this->assertSame(['Business Head'], $login->fresh()->getRoleNames()->all());
    }

    public function test_leaving_the_business_head_seat_takes_the_role_away(): void
    {
        $login = $this->loginFor($this->businessHead, 'Business Head');

        $this->businessHead->update(['designation' => Employee::DESIGNATION_CLUSTER]);

        $this->assertSame(['Cluster Manager'], $login->fresh()->getRoleNames()->all());
    }

    public function test_a_login_without_a_hierarchy_role_keeps_its_own_role(): void
    {
        $login = $this->loginFor($this->caller, 'MIS');

        $this->caller->update(['designation' => Employee::DESIGNATION_TEAM_LEADER]);

        $this->assertSame(['MIS'], $login->fresh()->getRoleNames()->all());
    }

    public function test_the_business_head_role_is_only_for_a_business_head(): void
    {
        $roles = app(HierarchyRoleService::class);

        $this->assertNotNull($roles->roleProblem($this->manager, 'Business Head'));
        $this->assertNotNull($roles->roleProblem($this->businessHead, 'Manager'));
        $this->assertNull($roles->roleProblem($this->businessHead, 'Business Head'));
        $this->assertNull($roles->roleProblem($this->businessHead, 'Admin'));
        $this->assertNull($roles->roleProblem($this->manager, 'Manager'));

        $this->actingAs($this->adminUser());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'employee_id' => $this->manager->id,
                'name' => 'Not A Business Head',
                'email' => 'not.business.head@example.com',
                'roles' => Role::findByName('Business Head')->id,
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['roles']);
    }

    /*
    |--------------------------------------------------------------------------
    | Screens
    |--------------------------------------------------------------------------
    */

    public function test_a_business_head_opens_the_screens_a_cluster_manager_opens(): void
    {
        $this->actingAs($this->loginFor($this->businessHead, 'Business Head'));

        $this->assertTrue(TeamPerformance::canAccess());
        $this->assertTrue(EmployeeHierarchy::canAccess());
        $this->assertTrue(TeamResource::canAccess());
        $this->assertTrue(LeadAssignmentReportResource::canViewAny());
        $this->assertTrue(IncentiveStats::canView());
        $this->assertTrue(ChangePassword::shouldRegisterNavigation());
        $this->assertTrue((new DailyCommitmentTeamView)->canSetExpectedOtp());
    }

    /*
    |--------------------------------------------------------------------------
    | Data stays inside the branch
    |--------------------------------------------------------------------------
    */

    public function test_a_business_head_sees_and_creates_continuity_rules_only_in_their_branch(): void
    {
        $service = app(CustomerJourneyDelegationService::class);
        $admin = $this->adminUser();

        $rule = fn (Employee $original, Employee $backup): array => [
            'delegating_manager_id' => $original->id,
            'acting_manager_id' => $backup->id,
            'start_at' => now(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'On leave.',
        ];

        $ownBranchRule = $service->create($rule($this->manager, $this->teamLeader), $admin);
        $otherBranchRule = $service->create($rule($this->otherManager, $this->otherTeamLeader), $admin);

        $businessHeadLogin = $this->loginFor($this->businessHead, 'Business Head');
        $this->actingAs($businessHeadLogin);

        $visible = CustomerJourneyDelegationResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($visible->contains($ownBranchRule->id));
        $this->assertFalse($visible->contains($otherBranchRule->id));

        $service->create($rule($this->teamLeader, $this->manager), $businessHeadLogin);
        $this->assertSame(3, CustomerJourneyDelegation::query()->count());

        $this->expectException(ValidationException::class);

        $service->create($rule($this->otherManager, $this->otherTeamLeader), $businessHeadLogin);
    }

    public function test_a_business_head_sees_their_branchs_pan_requests_old_and_new(): void
    {
        $bank = Bank::create(['bank_name' => 'Test Bank', 'loan_type' => 'personal', 'is_active' => true]);

        $request = fn (Employee $requester, array $snapshot): CustomerPanRequest => CustomerPanRequest::create([
            'customer_id' => Customer::factory()->create()->id,
            'pan_number' => 'ABCDE1234F',
            'requested_by' => $requester->id,
            'requested_by_emp_id' => $requester->emp_id,
            'requested_by_name' => $requester->emp_name,
            'requested_bank_id' => $bank->id,
            'requested_bank_name' => $bank->bank_name,
            'requested_loan_type' => 'personal_loan',
            'reason' => 'Duplicate PAN.',
            'status' => CustomerPanRequest::STATUS_PENDING,
            ...$snapshot,
        ]);

        $snapshotted = $request($this->caller, ['business_head_id' => $this->businessHead->id]);
        $raisedBeforeTheSnapshot = $request($this->caller, ['cluster_manager_id' => $this->cluster->id]);
        $otherBranch = $request($this->otherCaller, ['business_head_id' => $this->otherBusinessHead->id]);

        $this->actingAs($this->loginFor($this->businessHead, 'Business Head'));

        $visible = CustomerPanRequestResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($visible->contains($snapshotted->id));
        $this->assertTrue($visible->contains($raisedBeforeTheSnapshot->id));
        $this->assertFalse($visible->contains($otherBranch->id));
    }

    public function test_the_daily_commitment_filters_narrow_to_a_business_head(): void
    {
        $ids = app(DailyCommitmentService::class)->filterEmployeeIds($this->adminUser(), [
            'business_head_id' => $this->otherBusinessHead->id,
        ]);

        $this->assertEqualsCanonicalizing(
            HierarchyHelper::subordinateIds($this->otherBusinessHead)->all(),
            $ids->all(),
        );
    }

    public function test_sla_escalates_to_the_business_head_where_the_cluster_manager_level_is_skipped(): void
    {
        config()->set('journey_sla.reminder_minutes.document_verification', 30);
        config()->set('journey_sla.escalation_minutes.document_verification', 60);

        $customer = Customer::factory()->create([
            'assign_to' => $this->otherCaller->id,
            'journey_status' => 'sfl',
            'documents_submitted' => false,
        ]);
        $customer->forceFill(['created_at' => now()->subMinutes(90)])->saveQuietly();

        app(JourneySlaService::class)->checkBreaches();

        $this->assertSame(
            $this->otherBusinessHead->id,
            CustomerSlaBreach::query()->where('customer_id', $customer->id)->sole()->escalated_to_employee_id,
        );
    }
}
