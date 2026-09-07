<?php

namespace Tests\Feature;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Models\DailyCommitment;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use App\Services\DailyCommitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * A Manager's own commitment, their Team Leaders' and their Callers' are
 * three separate numbers. Adding them into one figure hides who is
 * behind, so every rollup in the module reports them level by level and
 * only ever shows the combined total as a clearly labelled extra.
 */
class DailyCommitmentLevelSplitTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller;

    private DailyCommitmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
        ]);

        $this->teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->manager->id,
        ]);

        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
        ]);

        $this->service = app(DailyCommitmentService::class);
    }

    public function test_the_period_rollup_keeps_every_level_apart(): void
    {
        $this->commit($this->manager, 5000000);
        $this->commit($this->teamLeader, 2000000);
        $this->commit($this->caller, 1000000);

        $levels = $this->service->summariseByLevel($this->rows());

        $this->assertSame(
            [Employee::DESIGNATION_MANAGER, Employee::DESIGNATION_TEAM_LEADER, Employee::DESIGNATION_CALLER],
            array_column($levels, 'designation'),
            'Levels are always reported top down.',
        );

        $this->assertSame(5000000.0, $levels[0]['summary']['committed_amount']);
        $this->assertSame(2000000.0, $levels[1]['summary']['committed_amount']);
        $this->assertSame(1000000.0, $levels[2]['summary']['committed_amount']);

        foreach ($levels as $level) {
            $this->assertSame(1, $level['summary']['people'], 'A level only ever counts its own people.');
        }
    }

    public function test_the_combined_total_still_agrees_with_the_levels(): void
    {
        $this->commit($this->manager, 5000000);
        $this->commit($this->teamLeader, 2000000);
        $this->commit($this->caller, 1000000);

        $rows = $this->rows();
        $levels = $this->service->summariseByLevel($rows);

        $this->assertSame(
            $this->service->summarise($rows)['committed_amount'],
            array_sum(array_column(array_column($levels, 'summary'), 'committed_amount')),
        );
    }

    public function test_a_level_nobody_is_on_is_left_out_rather_than_shown_empty(): void
    {
        $this->commit($this->caller, 1000000);

        $levels = $this->service->summariseByLevel(
            $this->service->rows(collect([$this->caller->id]), today()->startOfDay(), today()->endOfDay())
        );

        $this->assertCount(1, $levels);
        $this->assertSame(Employee::DESIGNATION_CALLER, $levels[0]['designation']);
    }

    public function test_the_monthly_rollup_is_split_the_same_way(): void
    {
        $this->target($this->manager, 9000000);
        $this->target($this->teamLeader, 4000000);
        $this->target($this->caller, 1500000);

        $ids = collect([$this->manager->id, $this->teamLeader->id, $this->caller->id]);
        $month = today()->startOfMonth();

        $levels = $this->service->monthlyRollupByLevel($ids, $month);

        $this->assertSame(9000000.0, $levels[0]['summary']['target']);
        $this->assertSame(4000000.0, $levels[1]['summary']['target']);
        $this->assertSame(1500000.0, $levels[2]['summary']['target']);

        // The combined rollup is the sum of the three, and no more.
        $this->assertSame(14500000.0, $this->service->monthlyRollup($ids, $month)['target']);
    }

    public function test_an_otp_target_never_lands_in_a_rupee_rollup(): void
    {
        $this->target($this->caller, 0, CommitmentStage::Otp, 40);

        $rollup = $this->service->monthlyRollup(collect([$this->caller->id]), today()->startOfMonth());

        $this->assertSame(0.0, $rollup['target']);
        $this->assertSame(1, $rollup['people_with_target']);
    }

    /*
    |--------------------------------------------------------------------------
    | Module vocabulary
    |--------------------------------------------------------------------------
    */

    public function test_the_module_calls_the_last_two_stages_approval_and_disbursal(): void
    {
        $this->assertSame('Approval', CommitmentStage::Approved->label());
        $this->assertSame('Disbursal', CommitmentStage::Disbursed->label());

        // The stored values are untouched — this is a wording change only.
        $this->assertSame('approved', CommitmentStage::Approved->value);
        $this->assertSame('disbursed', CommitmentStage::Disbursed->value);
    }

    public function test_disbursal_is_the_default_stage_for_a_target(): void
    {
        $this->assertSame(CommitmentStage::Disbursed, CommitmentStage::default());
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function rows(): Collection
    {
        return $this->service->rows(
            collect([$this->manager->id, $this->teamLeader->id, $this->caller->id]),
            today()->startOfDay(),
            today()->endOfDay(),
        );
    }

    private function commit(Employee $employee, float $amount): DailyCommitment
    {
        return DailyCommitment::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Disbursed,
            'commitment_amount' => $amount,
            'result' => CommitmentResult::InProgress,
        ]);
    }

    private function target(
        Employee $employee,
        float $amount,
        CommitmentStage $stage = CommitmentStage::Disbursed,
        int $count = 0,
        ?Carbon $month = null,
    ): MonthlyCommitmentTarget {
        return MonthlyCommitmentTarget::create([
            'employee_id' => $employee->id,
            'month' => ($month ?? today()->startOfMonth())->toDateString(),
            'stage' => $stage,
            'target_amount' => $amount,
            'target_count' => $count,
        ]);
    }
}
