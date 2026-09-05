<?php

namespace Tests\Feature;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Models\Customer;
use App\Models\CustomerStageHistory;
use App\Models\DailyCallerOtp;
use App\Models\DailyCommitment;
use App\Models\DailyCommitmentEntry;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Services\DailyCommitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The rules the module rests on:
 *
 *  - a day's achievement is ONLY the customer-wise fulfilment the
 *    employee declared for that day, so historical business can never
 *    drift into it (scenarios A and F);
 *  - a declared row counts at the highest stage the LMS proves the case
 *    reached, so Approved -> Rejected still counts as Approved;
 *  - current pipeline is a separate, undated figure.
 */
class DailyCommitmentModuleTest extends TestCase
{
    use RefreshDatabase;

    private Employee $teamLeader;

    private Employee $caller;

    private Employee $otherCaller;

    private DailyCommitmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Team Leader', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
        ]);

        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
        ]);

        $this->otherCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        $this->service = app(DailyCommitmentService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scenario A / F — historical business never leaks into today
    |--------------------------------------------------------------------------
    */

    public function test_scenario_a_an_old_approved_case_does_not_count_toward_todays_commitment(): void
    {
        // ₹5L approved yesterday, sitting in the LMS, never declared today.
        $this->approvedCase(500000, approvedOn: today()->subDay());

        $commitment = $this->commit(CommitmentStage::Approved, 1000000);

        $this->service->syncCommitment($commitment);

        $this->assertSame(0.0, $commitment->achievement_amount);
        $this->assertSame(0.0, $commitment->achievementPercentage());
    }

    public function test_scenario_f_an_old_underwriting_case_does_not_count_even_when_touched_today(): void
    {
        $customer = $this->underwritingCase(700000);
        // Someone edits the old case today — this used to credit it.
        $customer->forceFill(['updated_at' => now()])->saveQuietly();

        $commitment = $this->commit(CommitmentStage::Underwriting, 500000);

        $this->service->syncCommitment($commitment);

        $this->assertSame(0.0, $commitment->achievement_amount);
    }

    /*
    |--------------------------------------------------------------------------
    | Scenarios B - E — declared fulfilment
    |--------------------------------------------------------------------------
    */

    public function test_scenario_b_a_case_declared_today_at_approved_counts(): void
    {
        $customer = $this->approvedCase(500000);
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);

        $this->declare($commitment, $customer, CommitmentStage::Approved, 500000);
        $this->service->syncCommitment($commitment);

        $this->assertSame(500000.0, $commitment->achievement_amount);
        $this->assertSame(500000.0, $commitment->pending());
        $this->assertSame(50.0, $commitment->achievementPercentage());
    }

    public function test_scenario_c_a_declared_case_rejected_after_approval_still_counts_as_approved(): void
    {
        $customer = $this->approvedCase(500000);
        $customer->forceFill(['journey_status' => 'not_approved'])->saveQuietly();

        $commitment = $this->commit(CommitmentStage::Approved, 1000000);

        // The employee declares it as Rejected — the LMS history still
        // proves it reached Approved, so it counts.
        $entry = $this->declare($commitment, $customer, CommitmentStage::Underwriting, 500000, CommitmentStage::Rejected);

        $this->assertSame(CommitmentStage::Approved, $entry->effectiveStage());
        $this->assertTrue($entry->countsToward(CommitmentStage::Approved));

        $this->service->syncCommitment($commitment);
        $this->assertSame(500000.0, $commitment->achievement_amount);
    }

    public function test_scenario_d_underwriting_counts_for_underwriting_but_not_for_approved(): void
    {
        $customer = $this->underwritingCase(500000);

        $approvedCommitment = $this->commit(CommitmentStage::Approved, 1000000);
        $this->declare($approvedCommitment, $customer, CommitmentStage::Underwriting, 500000);
        $this->service->syncCommitment($approvedCommitment);
        $this->assertSame(0.0, $approvedCommitment->achievement_amount);

        $approvedCommitment->update(['commitment_stage' => CommitmentStage::Underwriting]);
        $this->service->syncCommitment($approvedCommitment->refresh());
        $this->assertSame(500000.0, $approvedCommitment->achievement_amount);
    }

    public function test_scenario_e_a_disbursed_case_counts_for_every_stage_at_or_below_it(): void
    {
        $customer = $this->disbursedCase(500000);

        foreach ([
            CommitmentStage::Sfl,
            CommitmentStage::Underwriting,
            CommitmentStage::Approved,
            CommitmentStage::Disbursed,
        ] as $stage) {
            $commitment = $this->commit($stage, 1000000);
            $this->declare($commitment, $customer, CommitmentStage::Disbursed, 500000);
            $this->service->syncCommitment($commitment);

            $this->assertSame(
                500000.0,
                $commitment->achievement_amount,
                "A disbursed case should count toward a {$stage->label()} commitment.",
            );

            $commitment->delete();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Stage mapping against the real LMS fields
    |--------------------------------------------------------------------------
    */

    public function test_docs_received_uses_documentation_status_not_the_existence_of_a_case(): void
    {
        $bare = Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'not_started',
            'eligibility_status' => 'not_eligible',
            'documentation_status' => 'pending',
            'approval_date' => null,
            'approved_loan_amount' => null,
            'sanctioned_loan_amount' => null,
            'underwriting_status' => null,
            'disbursal_status' => null,
        ]);

        $withDocs = Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'not_started',
            'eligibility_status' => 'not_eligible',
            'documentation_status' => 'complete',
            'approval_date' => null,
            'approved_loan_amount' => null,
            'sanctioned_loan_amount' => null,
            'underwriting_status' => null,
            'disbursal_status' => null,
        ]);

        $resolved = $this->service->highestStageFor(collect([$bare->id, $withDocs->id]));

        $this->assertNull($resolved[$bare->id]['stage'], 'A case with no documents has reached no stage.');
        $this->assertSame(CommitmentStage::DocsReceived, $resolved[$withDocs->id]['stage']);
    }

    public function test_sfl_is_the_eligibility_decision_taken_when_the_case_is_opened(): void
    {
        $eligible = Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'sfl',
            'eligibility_status' => 'eligible',
            'eligible_loan_amount' => 400000,
            'approval_date' => null,
            'approved_loan_amount' => null,
            'sanctioned_loan_amount' => null,
            'underwriting_status' => null,
            'disbursal_status' => null,
        ]);

        $resolved = $this->service->highestStageFor(collect([$eligible->id]));

        $this->assertSame(CommitmentStage::Sfl, $resolved[$eligible->id]['stage']);
        $this->assertSame(400000.0, $resolved[$eligible->id]['amount']);
    }

    public function test_a_dropped_case_is_not_treated_as_disbursed_despite_disbursal_finalized(): void
    {
        // CustomerJourneyService::sanction() sets disbursal_finalized true
        // even when the outcome is "dropped" — it must not read as Disbursed.
        $dropped = Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'dropped',
            'disbursal_status' => 'dropped',
            'disbursal_finalized' => true,
            'eligibility_status' => 'eligible',
            'approved_loan_amount' => 600000,
            'sanctioned_loan_amount' => null,
            'approval_date' => today(),
        ]);

        $resolved = $this->service->highestStageFor(collect([$dropped->id]));

        $this->assertSame(CommitmentStage::Approved, $resolved[$dropped->id]['stage']);
        $this->assertSame(CommitmentStage::Dropped, $resolved[$dropped->id]['outcome']);
    }

    public function test_stage_history_beats_a_downgraded_current_status(): void
    {
        $customer = $this->underwritingCase(500000);

        CustomerStageHistory::create([
            'customer_id' => $customer->id,
            'stage_name' => 'Underwriting Stage',
            'status_value' => 'Moved to Approved',
            'created_at' => today()->subDays(3),
            'updated_at' => today()->subDays(3),
        ]);

        $customer->forceFill(['journey_status' => 'not_approved'])->saveQuietly();

        $resolved = $this->service->highestStageFor(collect([$customer->id]));

        $this->assertSame(CommitmentStage::Approved, $resolved[$customer->id]['stage']);
        $this->assertSame(CommitmentStage::Rejected, $resolved[$customer->id]['outcome']);
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline stays separate
    |--------------------------------------------------------------------------
    */

    public function test_pipeline_is_built_only_from_declared_commitment_data(): void
    {
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);

        // Sitting in the LMS but never declared — the pipeline must not see it.
        $this->approvedCase(5000000);

        $stillLive = $this->approvedCase(500000);
        $inUnderwriting = $this->underwritingCase(300000);
        $done = $this->disbursedCase(900000);
        $lost = $this->approvedCase(400000);

        $this->declare($commitment, $stillLive, CommitmentStage::Approved, 500000);
        $this->declare($commitment, $inUnderwriting, CommitmentStage::Underwriting, 300000);
        $this->declare($commitment, $done, CommitmentStage::Disbursed, 900000);
        $this->declare($commitment, $lost, CommitmentStage::Approved, 400000, CommitmentStage::Dropped);

        $pipeline = $this->service->pipeline(
            collect([$this->caller->id]),
            today()->startOfDay(),
            today()->endOfDay(),
        )[$this->caller->id];

        // Only the two live, not-yet-disbursed declared cases.
        $this->assertSame(800000.0, $pipeline['total_amount']);
        $this->assertSame(2, $pipeline['total_count']);
        $this->assertSame(500000.0, $pipeline['stages'][CommitmentStage::Approved->value]['amount']);
        $this->assertSame(300000.0, $pipeline['stages'][CommitmentStage::Underwriting->value]['amount']);
        $this->assertSame(0.0, $pipeline['stages'][CommitmentStage::Disbursed->value]['amount']);
    }

    public function test_pipeline_is_never_added_into_achievement(): void
    {
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);
        $customer = $this->underwritingCase(300000);
        $this->declare($commitment, $customer, CommitmentStage::Underwriting, 300000);

        $this->service->syncCommitment($commitment);

        $row = $this->service->dailyRows(collect([$this->caller->id]), today())->first();

        $this->assertSame(0.0, $row['achieved'], 'Underwriting is below the committed Approved stage.');
        $this->assertSame(300000.0, $row['pipeline']['total_amount']);
    }

    /*
    |--------------------------------------------------------------------------
    | Filters, ordering and periods
    |--------------------------------------------------------------------------
    */

    public function test_the_role_filter_lists_only_that_level(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $callers = $this->service->filterEmployeeIds($admin, ['role' => Employee::DESIGNATION_CALLER]);
        $leaders = $this->service->filterEmployeeIds($admin, ['role' => Employee::DESIGNATION_TEAM_LEADER]);

        $this->assertEqualsCanonicalizing([$this->caller->id, $this->otherCaller->id], $callers->all());
        $this->assertEqualsCanonicalizing([$this->teamLeader->id], $leaders->all());

        // No role: everyone in scope.
        $this->assertCount(3, $this->service->filterEmployeeIds($admin, []));
    }

    public function test_a_role_filter_can_never_widen_what_someone_may_see(): void
    {
        $leaderUser = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $leaderUser->assignRole('Team Leader');

        $callers = $this->service->filterEmployeeIds($leaderUser, ['role' => Employee::DESIGNATION_CALLER]);

        $this->assertSame([$this->caller->id], $callers->all());
        $this->assertNotContains($this->otherCaller->id, $callers->all());
    }

    public function test_rows_are_ordered_down_the_hierarchy(): void
    {
        $cluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER, 'emp_name' => 'Zara Cluster']);
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'emp_name' => 'Yash Manager', 'cluster_id' => $cluster->id]);
        $leader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'emp_name' => 'Xena Leader', 'manager_id' => $manager->id]);
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'emp_name' => 'Aarav Caller', 'superviser_id' => $leader->id]);

        $ordered = $this->service
            ->hierarchicalOrder(Employee::query()->whereIn('id', [$caller->id, $leader->id, $cluster->id, $manager->id])->get())
            ->pluck('id')
            ->all();

        // Alphabetically the caller would come first; hierarchy wins.
        $this->assertSame([$cluster->id, $manager->id, $leader->id, $caller->id], $ordered);
    }

    public function test_period_presets_resolve_to_the_expected_boundaries(): void
    {
        [$start, $end] = DailyCommitmentService::resolveRange('today');
        $this->assertTrue($start->isSameDay(today()) && $end->isSameDay(today()));

        [$start, $end] = DailyCommitmentService::resolveRange('last_week');
        $this->assertSame(6, (int) $start->diffInDays($end->copy()->startOfDay()));

        [$start, $end] = DailyCommitmentService::resolveRange('this_month');
        $this->assertTrue($start->isSameDay(today()->startOfMonth()));
        $this->assertTrue($end->isSameDay(today()), 'This month runs till today, not month end.');

        [$start, $end] = DailyCommitmentService::resolveRange('last_month');
        $this->assertTrue($start->isSameDay(today()->subMonthNoOverflow()->startOfMonth()));
        $this->assertTrue($end->isSameDay(today()->subMonthNoOverflow()->endOfMonth()));

        [$start, $end] = DailyCommitmentService::resolveRange('custom', '2026-03-01', '2026-03-10');
        $this->assertSame('2026-03-01', $start->toDateString());
        $this->assertSame('2026-03-10', $end->toDateString());
    }

    public function test_a_period_sums_daily_commitments_and_counts_every_change(): void
    {
        foreach ([2, 1, 0] as $daysAgo) {
            $date = today()->subDays($daysAgo);

            $commitment = DailyCommitment::create([
                'employee_id' => $this->caller->id,
                'date' => $date,
                'commitment_stage' => CommitmentStage::Approved,
                'commitment_amount' => 1000000,
            ]);

            $customer = $this->approvedCase(400000);
            $this->declare($commitment, $customer, CommitmentStage::Approved, 400000);
            $this->service->syncCommitment($commitment);
        }

        $row = $this->service
            ->rows(collect([$this->caller->id]), today()->subDays(2)->startOfDay(), today()->endOfDay())
            ->first();

        $this->assertSame(3, $row['days']);
        $this->assertSame(3000000.0, $row['target']);
        $this->assertSame(1200000.0, $row['achieved']);
        $this->assertSame(3, $row['changes'], 'One logged movement per day.');
        $this->assertSame(CommitmentStage::Approved, $row['current_stage'], 'Final stage reached.');
    }

    public function test_presence_is_any_login_in_the_existing_screen_time_sessions(): void
    {
        $user = User::factory()->create(['employee_id' => $this->caller->id]);

        // Two sessions on one day, one on another — two present days.
        foreach ([[2, 9], [2, 17], [1, 10]] as [$daysAgo, $hour]) {
            UserLoginSession::create([
                'user_id' => $user->id,
                'employee_id' => $this->caller->id,
                'session_id' => "s-{$daysAgo}-{$hour}",
                'login_at' => today()->subDays($daysAgo)->setTime($hour, 0),
                // No screen time recorded at all — the login alone counts.
                'screen_time_seconds' => 0,
            ]);
        }

        $days = $this->service->presentDays(
            collect([$this->caller->id, $this->otherCaller->id]),
            today()->subDays(3)->startOfDay(),
            today()->endOfDay(),
        );

        $this->assertSame(2, $days[$this->caller->id]);
        $this->assertSame(0, $days[$this->otherCaller->id]);
        $this->assertTrue($this->service->presence(collect([$this->caller->id]), today()->subDays(2))[$this->caller->id]);
        $this->assertFalse($this->service->presence(collect([$this->caller->id]), today())[$this->caller->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Results, OTP, attendance, access, MTD
    |--------------------------------------------------------------------------
    */

    public function test_a_shortfall_is_in_progress_until_submitted_then_a_failure(): void
    {
        $customer = $this->approvedCase(400000);
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);
        $this->declare($commitment, $customer, CommitmentStage::Approved, 400000);

        $this->service->syncCommitment($commitment);
        $this->assertSame(CommitmentResult::InProgress, $commitment->result);

        $commitment->forceFill(['submitted_at' => now()])->save();
        $this->service->syncCommitment($commitment);
        $this->assertSame(CommitmentResult::Failed, $commitment->result);
    }

    public function test_beating_the_commitment_is_an_overachievement(): void
    {
        $customer = $this->disbursedCase(1500000);
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);
        $this->declare($commitment, $customer, CommitmentStage::Disbursed, 1500000);

        $this->service->syncCommitment($commitment);

        $this->assertSame(CommitmentResult::Overachieved, $commitment->result);
        $this->assertSame(150.0, $commitment->achievementPercentage());
    }

    public function test_an_otp_commitment_is_counted_from_cases_opened_that_day(): void
    {
        Customer::factory()->count(3)->create([
            'employee_id' => $this->caller->id,
            'created_at' => today()->setTime(10, 0),
            'updated_at' => today()->setTime(10, 0),
        ]);

        Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'created_at' => today()->subDay(),
            'updated_at' => today()->subDay(),
        ]);

        $commitment = DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Otp,
            'commitment_count' => 3,
        ]);

        $this->service->syncCommitment($commitment);

        $this->assertSame(3, $commitment->achievement_count, 'Yesterday\'s case must not count.');
        $this->assertSame(CommitmentResult::Met, $commitment->result);
    }

    public function test_presence_and_otp_come_from_the_existing_login_and_customer_data(): void
    {
        UserLoginSession::create([
            'user_id' => User::factory()->create(['employee_id' => $this->caller->id])->id,
            'employee_id' => $this->caller->id,
            'session_id' => 'test-session',
            'login_at' => today()->setTime(9, 30),
        ]);

        Customer::factory()->count(2)->create([
            'employee_id' => $this->caller->id,
            'created_at' => today()->setTime(10, 0),
            'updated_at' => today()->setTime(10, 0),
        ]);

        DailyCallerOtp::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'expected_otp' => 4,
        ]);

        $rows = $this->service->dailyRows(collect([$this->caller->id, $this->otherCaller->id]), today());

        $callerRow = $rows->firstWhere(fn (array $row): bool => $row['employee']->id === $this->caller->id);
        $otherRow = $rows->firstWhere(fn (array $row): bool => $row['employee']->id === $this->otherCaller->id);

        $this->assertTrue($callerRow['present']);
        $this->assertFalse($otherRow['present']);
        $this->assertSame(2, $callerRow['actual_otp']);
        $this->assertSame(4, $callerRow['expected_otp']);
        $this->assertSame(50.0, $callerRow['otp_percentage']);
    }

    public function test_a_caller_only_sees_their_own_commitment_and_a_team_leader_sees_their_callers(): void
    {
        $callerUser = User::factory()->create(['employee_id' => $this->caller->id]);
        $callerUser->assignRole('Caller');

        $this->assertEqualsCanonicalizing(
            [$this->caller->id],
            $this->service->visibleEmployeeIds($callerUser)->all(),
        );
        $this->assertFalse($this->service->canView($callerUser, $this->otherCaller->id));

        $leaderUser = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $leaderUser->assignRole('Team Leader');

        $this->assertEqualsCanonicalizing(
            [$this->teamLeader->id, $this->caller->id],
            $this->service->visibleEmployeeIds($leaderUser)->all(),
        );
        $this->assertFalse($this->service->canView($leaderUser, $this->otherCaller->id));
    }

    public function test_an_admin_sees_everyone(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->assertTrue($this->service->canView($admin, $this->otherCaller->id));
    }

    public function test_mtd_is_the_sum_of_declared_daily_fulfilment(): void
    {
        MonthlyCommitmentTarget::create([
            'employee_id' => $this->caller->id,
            'month' => today()->startOfMonth(),
            'stage' => CommitmentStage::Approved,
            'target_amount' => 2000000,
        ]);

        // Declared today.
        $customer = $this->approvedCase(500000);
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);
        $this->declare($commitment, $customer, CommitmentStage::Approved, 500000);

        // Sitting in the LMS this month but never declared — must not count.
        $this->approvedCase(900000, approvedOn: today()->startOfMonth());

        $position = $this->service->monthlyPosition($this->caller->id, today());

        $this->assertSame(2000000.0, $position['target']);
        $this->assertSame(500000.0, $position['achieved']);
        $this->assertSame(1500000.0, $position['pending']);
        $this->assertSame(25.0, $position['percentage']);
    }

    public function test_the_monthly_target_never_touches_the_existing_lms_target(): void
    {
        $this->assertTrue(Schema::hasTable('monthly_commitment_targets'));

        MonthlyCommitmentTarget::create([
            'employee_id' => $this->caller->id,
            'month' => today()->startOfMonth(),
            'stage' => CommitmentStage::Disbursed,
            'target_amount' => 1000000,
        ]);

        $this->assertDatabaseCount('employee_targets', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function commit(CommitmentStage $stage, float $amount): DailyCommitment
    {
        return DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'commitment_stage' => $stage,
            'commitment_amount' => $amount,
            'result' => CommitmentResult::InProgress,
        ]);
    }

    private function declare(
        DailyCommitment $commitment,
        Customer $customer,
        CommitmentStage $stage,
        float $amount,
        ?CommitmentStage $outcome = null,
    ): DailyCommitmentEntry {
        $resolved = $this->service->highestStageFor(collect([$customer->id]))[$customer->id] ?? null;

        return DailyCommitmentEntry::create([
            'daily_commitment_id' => $commitment->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'stage' => $stage,
            'lms_highest_stage' => $resolved['stage'],
            'outcome' => $outcome ?? $resolved['outcome'],
            'amount' => $amount,
        ]);
    }

    private function approvedCase(float $amount, ?Carbon $approvedOn = null): Customer
    {
        return Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'approved',
            'eligibility_status' => 'eligible',
            'documentation_status' => 'complete',
            'approved_loan_amount' => $amount,
            'sanctioned_loan_amount' => null,
            'approval_date' => $approvedOn ?? today(),
            'disbursal_date' => null,
            'disbursal_status' => null,
            'disbursal_finalized' => false,
        ]);
    }

    private function disbursedCase(float $amount): Customer
    {
        return Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'sanctioned',
            'eligibility_status' => 'eligible',
            'documentation_status' => 'complete',
            'approved_loan_amount' => $amount,
            'sanctioned_loan_amount' => $amount,
            'approval_date' => today()->subDay(),
            'disbursal_date' => today(),
            'disbursal_status' => 'disbursed',
            'disbursal_finalized' => true,
        ]);
    }

    private function underwritingCase(float $amount): Customer
    {
        return Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'underwriting',
            'eligibility_status' => 'eligible',
            'documentation_status' => 'complete',
            'eligible_loan_amount' => $amount,
            'approved_loan_amount' => null,
            'sanctioned_loan_amount' => null,
            'approval_date' => null,
            'disbursal_date' => null,
            'disbursal_status' => null,
            'disbursal_finalized' => false,
        ]);
    }
}
