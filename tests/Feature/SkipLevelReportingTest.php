<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\DailyCommitmentService;
use App\Services\HierarchyService;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Services\MonthlyTargetGate;
use App\Support\HierarchyHelper;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Anyone may report to any higher level. The branch below a skipped level
 * must be counted and seen by the next boss up — and never by the level
 * that was skipped.
 *
 * Business Head BH
 * ├ Cluster Manager CM
 * │ ├ Manager M
 * │ │ ├ Team Leader TL ── Caller C1
 * │ │ └ Caller C3                      (skips Team Leader)
 * │ └ Team Leader T2 ── Caller C2      (skips Manager)
 * └ Manager M2                         (skips Cluster Manager)
 *   └ Team Leader T3 ── Caller C4
 *
 * Business Head BH2 ── Cluster Manager CM2 ── Caller X   (another branch)
 */
class SkipLevelReportingTest extends TestCase
{
    use RefreshDatabase;

    private const CALLER_TARGET = 2500000.0;

    private const TOP_UP = 3000000.0;

    private Employee $businessHead;

    private Employee $cluster;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $callerUnderTeamLeader;

    private Employee $callerUnderManager;

    private Employee $skipLevelTeamLeader;

    private Employee $callerUnderSkipLevel;

    private Employee $managerUnderBusinessHead;

    private Employee $teamLeaderUnderThatManager;

    private Employee $callerUnderThatTeamLeader;

    private Employee $otherBusinessHead;

    private Employee $otherCluster;

    private Employee $otherCaller;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Business Head', 'Cluster Manager', 'Manager', 'Team Leader', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->businessHead = $this->employee(Employee::DESIGNATION_BUSINESS_HEAD);
        $this->cluster = $this->employee(Employee::DESIGNATION_CLUSTER, ['business_head_id' => $this->businessHead->id]);
        $this->manager = $this->employee(Employee::DESIGNATION_MANAGER, [
            'cluster_id' => $this->cluster->id,
            'business_head_id' => $this->businessHead->id,
        ]);
        $this->teamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, [
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
            'business_head_id' => $this->businessHead->id,
        ]);
        $this->callerUnderTeamLeader = $this->employee(Employee::DESIGNATION_CALLER, [
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
            'business_head_id' => $this->businessHead->id,
        ]);
        $this->callerUnderManager = $this->employee(Employee::DESIGNATION_CALLER, [
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
            'business_head_id' => $this->businessHead->id,
        ]);
        $this->skipLevelTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, [
            'cluster_id' => $this->cluster->id,
            'business_head_id' => $this->businessHead->id,
        ]);
        $this->callerUnderSkipLevel = $this->employee(Employee::DESIGNATION_CALLER, [
            'superviser_id' => $this->skipLevelTeamLeader->id,
            'cluster_id' => $this->cluster->id,
            'business_head_id' => $this->businessHead->id,
        ]);
        $this->managerUnderBusinessHead = $this->employee(Employee::DESIGNATION_MANAGER, [
            'business_head_id' => $this->businessHead->id,
        ]);
        $this->teamLeaderUnderThatManager = $this->employee(Employee::DESIGNATION_TEAM_LEADER, [
            'manager_id' => $this->managerUnderBusinessHead->id,
            'business_head_id' => $this->businessHead->id,
        ]);
        $this->callerUnderThatTeamLeader = $this->employee(Employee::DESIGNATION_CALLER, [
            'superviser_id' => $this->teamLeaderUnderThatManager->id,
            'manager_id' => $this->managerUnderBusinessHead->id,
            'business_head_id' => $this->businessHead->id,
        ]);

        $this->otherBusinessHead = $this->employee(Employee::DESIGNATION_BUSINESS_HEAD);
        $this->otherCluster = $this->employee(Employee::DESIGNATION_CLUSTER, ['business_head_id' => $this->otherBusinessHead->id]);
        $this->otherCaller = $this->employee(Employee::DESIGNATION_CALLER, [
            'cluster_id' => $this->otherCluster->id,
            'business_head_id' => $this->otherBusinessHead->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(int $designation, array $attributes = []): Employee
    {
        $employee = Employee::factory()->create([
            'designation' => $designation,
            'exit_status' => 'no',
            'exit_date' => null,
            'reporting_date' => null,
            'category' => (string) self::CALLER_TARGET,
            ...$attributes,
        ]);

        // Only an employee who can sign in is waited on for a target.
        User::factory()->create(['employee_id' => $employee->id]);

        return $employee;
    }

    private function userOf(Employee $employee, ?string $role = null): User
    {
        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function adminUser(): User
    {
        return User::factory()->create()->assignRole('Admin');
    }

    private function leave(Employee $employee): void
    {
        $employee->forceFill(['exit_status' => 'yes', 'exit_date' => now()->subDay()->toDateString()])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Who is counted where
    |--------------------------------------------------------------------------
    */

    public function test_a_cluster_manager_counts_a_team_leader_who_skips_the_manager_level(): void
    {
        $this->assertEqualsCanonicalizing([
            $this->cluster->id,
            $this->manager->id,
            $this->teamLeader->id,
            $this->callerUnderTeamLeader->id,
            $this->callerUnderManager->id,
            $this->skipLevelTeamLeader->id,
            $this->callerUnderSkipLevel->id,
        ], HierarchyHelper::subordinateIds($this->cluster)->all());
    }

    public function test_a_manager_never_counts_a_team_leader_who_skips_them(): void
    {
        $this->assertEqualsCanonicalizing([
            $this->manager->id,
            $this->teamLeader->id,
            $this->callerUnderTeamLeader->id,
            $this->callerUnderManager->id,
        ], HierarchyHelper::subordinateIds($this->manager)->all());
    }

    public function test_a_caller_reporting_straight_to_a_manager_is_one_of_their_callers(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->callerUnderTeamLeader->id, $this->callerUnderManager->id],
            HierarchyHelper::callerIds($this->manager)->all(),
        );
    }

    public function test_a_business_head_counts_their_whole_branch_and_no_other(): void
    {
        $ids = HierarchyHelper::subordinateIds($this->businessHead);

        $this->assertCount(11, $ids);
        $this->assertTrue($ids->contains($this->callerUnderSkipLevel->id));
        $this->assertTrue($ids->contains($this->callerUnderThatTeamLeader->id));
        $this->assertFalse($ids->contains($this->otherCluster->id));
        $this->assertFalse($ids->contains($this->otherCaller->id));
    }

    public function test_a_column_pointing_at_the_wrong_level_is_ignored(): void
    {
        $misfiled = $this->employee(Employee::DESIGNATION_MANAGER, ['cluster_id' => $this->manager->id]);

        $this->assertNull($misfiled->directBossId());
        $this->assertFalse(HierarchyHelper::subordinateIds($this->manager)->contains($misfiled->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Targets
    |--------------------------------------------------------------------------
    */

    public function test_a_cluster_managers_target_carries_the_skip_level_team_and_its_top_up(): void
    {
        // C1, C3 and C2, plus a top-up each for TL and T2 (one caller apiece).
        $this->assertSame(
            3 * self::CALLER_TARGET + 2 * self::TOP_UP,
            (new AchievementCalculatorService)->getTarget($this->cluster),
        );
    }

    public function test_a_managers_target_leaves_out_the_team_that_skips_them(): void
    {
        // C1 and C3, plus TL's top-up. Nothing from T2.
        $this->assertSame(
            2 * self::CALLER_TARGET + self::TOP_UP,
            (new AchievementCalculatorService)->getTarget($this->manager),
        );
    }

    public function test_a_business_heads_target_rolls_up_every_level_below_them(): void
    {
        // C1, C2, C3 and C4, plus top-ups for TL, T2 and T3.
        $this->assertSame(
            4 * self::CALLER_TARGET + 3 * self::TOP_UP,
            (new AchievementCalculatorService)->getTarget($this->businessHead),
        );
    }

    public function test_an_exited_skip_level_team_leader_stops_counting_but_stays_visible(): void
    {
        $this->leave($this->skipLevelTeamLeader);

        $this->assertSame(
            2 * self::CALLER_TARGET + self::TOP_UP,
            (new AchievementCalculatorService)->getTarget($this->cluster),
        );
        $this->assertFalse(HierarchyHelper::subordinateIds($this->cluster)->contains($this->callerUnderSkipLevel->id));
        $this->assertTrue(HierarchyHelper::visibleSubordinateIds($this->cluster)->contains($this->callerUnderSkipLevel->id));
    }

    /*
    |--------------------------------------------------------------------------
    | What people see
    |--------------------------------------------------------------------------
    */

    public function test_a_cluster_managers_direct_reports_include_the_skip_level_team_leader(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->manager->id, $this->skipLevelTeamLeader->id],
            HierarchyHelper::children($this->cluster)->pluck('id')->all(),
        );
    }

    public function test_admin_starts_at_business_heads_and_cluster_managers_with_nobody_above(): void
    {
        $unownedCluster = $this->employee(Employee::DESIGNATION_CLUSTER);

        $this->assertEqualsCanonicalizing(
            [$this->businessHead->id, $this->otherBusinessHead->id, $unownedCluster->id],
            HierarchyHelper::directReportees($this->adminUser())->pluck('id')->all(),
        );
    }

    public function test_admin_sees_business_heads(): void
    {
        $ids = HierarchyHelper::visibleEmployeeIds($this->adminUser());

        $this->assertTrue($ids->contains($this->businessHead->id));
        $this->assertTrue($ids->contains($this->otherCaller->id));
    }

    public function test_a_business_head_sees_only_their_own_branch(): void
    {
        $user = $this->userOf($this->businessHead, 'Business Head');

        $visible = HierarchyHelper::visibleEmployeeIds($user);
        $leads = HierarchyService::visibleEmployeeIds($user);

        foreach ([$visible->all(), $leads] as $ids) {
            $this->assertContains($this->callerUnderSkipLevel->id, $ids);
            $this->assertContains($this->callerUnderThatTeamLeader->id, $ids);
            $this->assertNotContains($this->otherCaller->id, $ids);
        }
    }

    public function test_the_login_list_shows_the_skip_level_team_but_not_the_viewer(): void
    {
        $ids = HierarchyHelper::loginVisibleEmployeeIds($this->userOf($this->cluster));

        $this->assertTrue($ids->contains($this->skipLevelTeamLeader->id));
        $this->assertTrue($ids->contains($this->callerUnderSkipLevel->id));
        $this->assertFalse($ids->contains($this->cluster->id));
    }

    public function test_the_breadcrumb_follows_the_real_chain(): void
    {
        $this->assertSame(
            [
                $this->businessHead->emp_name,
                $this->cluster->emp_name,
                $this->skipLevelTeamLeader->emp_name,
                $this->callerUnderSkipLevel->emp_name,
            ],
            array_column(HierarchyHelper::breadcrumb($this->callerUnderSkipLevel), 'label'),
        );
    }

    public function test_the_continuity_pool_stops_at_the_cluster_manager(): void
    {
        $pool = HierarchyHelper::employeeHierarchyIds($this->callerUnderSkipLevel);

        $this->assertEqualsCanonicalizing(HierarchyHelper::subordinateIds($this->cluster)->all(), $pool->all());
        $this->assertFalse($pool->contains($this->businessHead->id));
        $this->assertFalse($pool->contains($this->managerUnderBusinessHead->id));
    }

    public function test_the_commitment_ordering_places_a_skip_level_team_after_the_managers(): void
    {
        $order = app(DailyCommitmentService::class)->hierarchicalOrder(collect([
            $this->callerUnderSkipLevel,
            $this->skipLevelTeamLeader,
            $this->callerUnderManager,
            $this->callerUnderTeamLeader,
            $this->teamLeader,
            $this->manager,
            $this->cluster,
            $this->businessHead,
        ]));

        $this->assertSame([
            $this->businessHead->id,
            $this->cluster->id,
            $this->manager->id,
            $this->teamLeader->id,
            $this->callerUnderTeamLeader->id,
            $this->callerUnderManager->id,
            $this->skipLevelTeamLeader->id,
            $this->callerUnderSkipLevel->id,
        ], $order->pluck('id')->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Customer journey
    |--------------------------------------------------------------------------
    */

    public function test_manager_stage_work_under_a_skipped_manager_goes_to_the_cluster_manager(): void
    {
        $journey = app(CustomerJourneyAccessService::class);

        $skipLevelCase = (new Customer)->forceFill(['assign_to' => $this->callerUnderSkipLevel->id]);
        $normalCase = (new Customer)->forceFill(['assign_to' => $this->callerUnderTeamLeader->id]);

        $this->assertTrue($journey->naturalManagerFor($skipLevelCase)->is($this->cluster));
        $this->assertTrue($journey->naturalManagerFor($normalCase)->is($this->manager));
        $this->assertSame(
            [$this->callerUnderSkipLevel->id, $this->skipLevelTeamLeader->id, $this->cluster->id, $this->businessHead->id],
            $journey->responsibleEmployeeChain($skipLevelCase)->all(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Monthly commitment targets
    |--------------------------------------------------------------------------
    */

    public function test_the_cluster_manager_owns_the_targets_under_a_skipped_manager(): void
    {
        $this->assertEqualsCanonicalizing([
            $this->manager->id,
            $this->teamLeader->id,
            $this->skipLevelTeamLeader->id,
            $this->callerUnderSkipLevel->id,
        ], app(MonthlyTargetGate::class)->responsibleFor($this->userOf($this->cluster))->pluck('id')->all());
    }

    public function test_a_manager_still_owns_only_their_own_callers(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->callerUnderTeamLeader->id, $this->callerUnderManager->id],
            app(MonthlyTargetGate::class)->responsibleFor($this->userOf($this->manager))->pluck('id')->all(),
        );
    }

    public function test_a_business_head_owns_what_no_cluster_manager_sits_over_and_nothing_elsewhere(): void
    {
        $gate = app(MonthlyTargetGate::class);
        $user = $this->userOf($this->businessHead, 'Business Head');

        // C4's target still belongs to their own Manager, M2.
        $this->assertEqualsCanonicalizing([
            $this->managerUnderBusinessHead->id,
            $this->teamLeaderUnderThatManager->id,
        ], $gate->responsibleFor($user)->pluck('id')->all());
        $this->assertTrue($gate->targetSetterFor($this->callerUnderThatTeamLeader)->is($this->managerUnderBusinessHead));

        $this->assertTrue($gate->canSetTargetFor($user, $this->callerUnderSkipLevel->id));
        $this->assertFalse($gate->canSetTargetFor($user, $this->otherCaller->id));
        $this->assertTrue($gate->isTargetSetter($user));
    }

    public function test_the_business_head_role_alone_never_reaches_the_whole_company(): void
    {
        $user = User::factory()->create()->assignRole('Business Head');
        $gate = app(MonthlyTargetGate::class);

        $this->assertFalse($gate->canSetTargetFor($user, $this->otherCaller->id));
        $this->assertFalse($gate->isTargetSetter($user));
        $this->assertTrue($gate->responsibleFor($user)->isEmpty());
    }

    public function test_an_employee_is_told_to_chase_the_nearest_setter(): void
    {
        $gate = app(MonthlyTargetGate::class);

        $this->assertTrue($gate->targetSetterFor($this->callerUnderSkipLevel)->is($this->cluster));
        $this->assertTrue($gate->targetSetterFor($this->callerUnderTeamLeader)->is($this->manager));
        $this->assertTrue($gate->targetSetterFor($this->skipLevelTeamLeader)->is($this->cluster));
        $this->assertTrue($gate->targetSetterFor($this->teamLeaderUnderThatManager)->is($this->businessHead));
    }

    public function test_when_a_manager_leaves_their_callers_targets_pass_to_the_cluster_manager(): void
    {
        $this->leave($this->manager);

        $gate = app(MonthlyTargetGate::class);
        $responsible = $gate->responsibleFor($this->userOf($this->cluster))->pluck('id');

        $this->assertTrue($gate->targetSetterFor($this->callerUnderTeamLeader)->is($this->cluster));
        $this->assertTrue($responsible->contains($this->callerUnderTeamLeader->id));
        $this->assertTrue($responsible->contains($this->callerUnderManager->id));
        $this->assertTrue($gate->canSetTargetFor($this->userOf($this->cluster), $this->callerUnderManager->id));
    }

    public function test_a_caller_with_nobody_above_them_is_never_the_admins_duty(): void
    {
        $orphan = $this->employee(Employee::DESIGNATION_CALLER);
        $exitedManagersCaller = $this->employee(Employee::DESIGNATION_CALLER, ['manager_id' => $this->managerUnderBusinessHead->id]);
        $this->leave($this->managerUnderBusinessHead);

        $gate = app(MonthlyTargetGate::class);
        $admin = $this->adminUser();
        $responsible = $gate->responsibleFor($admin)->pluck('id');

        // Nobody reports directly to the Admin, so neither locks them out.
        $this->assertFalse($responsible->contains($orphan->id));
        $this->assertFalse($responsible->contains($this->callerUnderTeamLeader->id));
        $this->assertTrue($responsible->contains($this->skipLevelTeamLeader->id));
        $this->assertNull($gate->targetSetterFor($orphan));

        // The Business Head above the exited Manager picks that caller up.
        $this->assertTrue($gate->targetSetterFor($exitedManagersCaller)->is($this->businessHead));

        // The Admin can still fix an orphan's target by hand.
        $this->assertTrue($gate->canSetTargetFor($admin, $orphan->id));
    }
}
