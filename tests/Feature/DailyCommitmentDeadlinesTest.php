<?php

namespace Tests\Feature;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Filament\Pages\DailyCommitmentDashboard;
use App\Filament\Pages\MyDailyCommitment;
use App\Livewire\DailyCommitmentPrompt;
use App\Models\Customer;
use App\Models\DailyCommitment;
use App\Models\DailyCommitmentEntry;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use App\Models\User;
use App\Services\DailyCommitmentGate;
use App\Services\DailyCommitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The two halves of the day, and what happens when either is missed.
 *
 *  09:50 — the promise must exist.
 *  18:30 — it must be answered.
 *
 * Miss either and the panel closes behind you until it is put right. A
 * day can also be answered in parts: ₹10L promised at Approval and
 * brought back as ₹7L Approval + ₹3L SFL settles as PARTIALLY MET —
 * neither a clean pass nor a failure.
 */
class DailyCommitmentDeadlinesTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    private User $user;

    private DailyCommitmentService $service;

    private DailyCommitmentGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'exit_status' => 'no',
        ]);

        $this->user = User::factory()->create(['employee_id' => $this->caller->id]);
        $this->user->assignRole('Caller');

        // The monthly-target gate is a separate block; give this employee
        // a target so only the daily deadlines are under test here.
        MonthlyCommitmentTarget::create([
            'employee_id' => $this->caller->id,
            'month' => today()->startOfMonth(),
            'stage' => CommitmentStage::Disbursed,
            'target_amount' => 10000000,
            'target_count' => 0,
        ]);

        $this->service = app(DailyCommitmentService::class);
        $this->gate = app(DailyCommitmentGate::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfilling a commitment in parts
    |--------------------------------------------------------------------------
    */

    public function test_a_day_made_up_from_lower_stages_settles_as_partially_met(): void
    {
        // ₹10L promised at Approval.
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);

        // ₹7L actually approved, and ₹3L that only got as far as SFL.
        $this->declare($commitment, $this->approvedCase(700000), CommitmentStage::Approved, 700000);
        $this->declare($commitment, $this->sflCase(300000), CommitmentStage::Sfl, 300000);

        $this->close($commitment);

        $commitment->refresh();

        // Only the Approval money earns credit against the promise...
        $this->assertSame(700000.0, (float) $commitment->achievement_amount);
        // ...and the SFL money is carried separately, never folded in.
        $this->assertSame(300000.0, (float) $commitment->below_stage_amount);
        $this->assertSame(1000000.0, $commitment->totalAchieved());

        // The number was made, but not at the stage promised.
        $this->assertSame(CommitmentResult::Partial, $commitment->result);
    }

    public function test_the_whole_number_at_the_committed_stage_is_a_clean_pass(): void
    {
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);

        $this->declare($commitment, $this->approvedCase(1000000), CommitmentStage::Approved, 1000000);

        $this->close($commitment);

        $this->assertSame(CommitmentResult::Met, $commitment->refresh()->result);
        $this->assertSame(0.0, (float) $commitment->below_stage_amount);
    }

    public function test_business_above_the_committed_stage_still_counts_in_full(): void
    {
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);

        // Disbursal sits above Approval on the ladder, so it counts.
        $this->declare($commitment, $this->disbursedCase(1200000), CommitmentStage::Disbursed, 1200000);

        $this->close($commitment);

        $this->assertSame(CommitmentResult::Overachieved, $commitment->refresh()->result);
    }

    public function test_falling_short_on_the_total_is_still_a_failure(): void
    {
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);

        $this->declare($commitment, $this->approvedCase(400000), CommitmentStage::Approved, 400000);
        $this->declare($commitment, $this->sflCase(200000), CommitmentStage::Sfl, 200000);

        $this->close($commitment);

        // ₹6L against a ₹10L promise — parts do not rescue a short day.
        $this->assertSame(CommitmentResult::Failed, $commitment->refresh()->result);
    }

    public function test_a_partial_day_is_never_called_partial_while_it_is_still_running(): void
    {
        $commitment = $this->commit(CommitmentStage::Approved, 1000000);

        $this->declare($commitment, $this->approvedCase(700000), CommitmentStage::Approved, 700000);
        $this->declare($commitment, $this->sflCase(300000), CommitmentStage::Sfl, 300000);

        $this->service->syncCommitment($commitment->refresh());

        $this->assertSame(CommitmentResult::InProgress, $commitment->refresh()->result);
    }

    /*
    |--------------------------------------------------------------------------
    | 09:50 — the promise
    |--------------------------------------------------------------------------
    */

    public function test_nobody_is_blocked_before_the_morning_deadline(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('09:00'));

        $this->assertFalse($this->gate->isBlocked($this->user));
    }

    public function test_past_the_morning_deadline_a_missing_commitment_closes_the_panel(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $status = $this->gate->status($this->user);

        $this->assertTrue($status['blocked']);
        $this->assertSame(DailyCommitmentGate::REASON_COMMIT, $status['reason']);
    }

    public function test_giving_the_commitment_clears_the_morning_block(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->commit(CommitmentStage::Disbursed, 1000000);

        $this->gate->forget();

        $this->assertFalse($this->gate->isBlocked($this->user));
    }

    /*
    |--------------------------------------------------------------------------
    | 18:30 — the answer
    |--------------------------------------------------------------------------
    */

    public function test_past_the_evening_deadline_an_unanswered_commitment_closes_the_panel(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('18:45'));

        $this->commit(CommitmentStage::Approved, 1000000);

        $this->gate->forget();

        $status = $this->gate->status($this->user);

        $this->assertTrue($status['blocked']);
        $this->assertSame(DailyCommitmentGate::REASON_DECLARE, $status['reason']);
        $this->assertFalse($status['overdue']);
    }

    public function test_submitting_the_declaration_clears_the_evening_block(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('18:45'));

        $commitment = $this->commit(CommitmentStage::Approved, 1000000);
        $commitment->forceFill(['submitted_at' => now()])->save();

        $this->gate->forget();

        $this->assertFalse($this->gate->isBlocked($this->user));
    }

    public function test_yesterdays_unanswered_commitment_blocks_today(): void
    {
        DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today()->subDay(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
            'result' => CommitmentResult::InProgress,
        ]);

        // Well before this morning's own deadline: the block is yesterday's.
        Carbon::setTestNow(today()->setTimeFromTimeString('09:00'));

        $status = $this->gate->status($this->user);

        $this->assertTrue($status['blocked']);
        $this->assertSame(DailyCommitmentGate::REASON_DECLARE, $status['reason']);
        $this->assertTrue($status['overdue']);
        $this->assertSame(today()->subDay()->toDateString(), $status['date']->toDateString());
    }

    public function test_an_employee_off_the_number_carrying_designations_is_never_blocked(): void
    {
        $cluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
            'exit_status' => 'no',
        ]);

        $user = User::factory()->create(['employee_id' => $cluster->id]);

        Carbon::setTestNow(today()->setTimeFromTimeString('19:00'));

        $this->assertFalse($this->gate->isBlocked($user));
    }

    /*
    |--------------------------------------------------------------------------
    | The block, as the employee meets it
    |--------------------------------------------------------------------------
    */

    public function test_a_blocked_user_is_redirected_to_my_commitment(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->actingAs($this->user)
            ->get(DailyCommitmentDashboard::getUrl())
            ->assertRedirect(MyDailyCommitment::getUrl());
    }

    public function test_my_commitment_itself_stays_reachable_while_blocked(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->actingAs($this->user)
            ->get(MyDailyCommitment::getUrl())
            ->assertSuccessful();
    }

    public function test_the_prompt_gives_the_commitment_without_leaving_the_page(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->actingAs($this->user);

        Livewire::test(DailyCommitmentPrompt::class)
            ->set('stage', CommitmentStage::Approved->value)
            ->set('amount', '1000000')
            ->call('commit');

        $this->assertDatabaseHas('daily_commitments', [
            'employee_id' => $this->caller->id,
            'commitment_stage' => CommitmentStage::Approved->value,
            'commitment_amount' => 1000000,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Closing the day
    |--------------------------------------------------------------------------
    */

    public function test_a_day_cannot_be_closed_without_naming_the_cases(): void
    {
        $this->actingAs($this->user);

        $this->commit(CommitmentStage::Approved, 1000000);

        Livewire::test(MyDailyCommitment::class)
            ->call('submitFinalStatus');

        // Still open: an empty list is never a declaration.
        $this->assertNull(DailyCommitment::first()->submitted_at);
    }

    public function test_a_nil_day_needs_a_reason_before_it_closes(): void
    {
        $this->actingAs($this->user);

        $this->commit(CommitmentStage::Approved, 1000000);

        Livewire::test(MyDailyCommitment::class)
            ->set('nothingReason', 'nope')
            ->call('declareNothing');

        $this->assertNull(DailyCommitment::first()->submitted_at);

        Livewire::test(MyDailyCommitment::class)
            ->set('nothingReason', 'Two files were pushed back by credit, nothing else moved.')
            ->call('declareNothing');

        $commitment = DailyCommitment::first();

        $this->assertNotNull($commitment->submitted_at);
        $this->assertSame(CommitmentResult::Failed, $commitment->result);
        $this->assertStringContainsString('pushed back by credit', $commitment->declaration_note);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
    ): DailyCommitmentEntry {
        $resolved = $this->service->highestStageFor(collect([$customer->id]))[$customer->id] ?? null;

        return DailyCommitmentEntry::create([
            'daily_commitment_id' => $commitment->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'stage' => $stage,
            'lms_highest_stage' => $resolved['stage'],
            'outcome' => $resolved['outcome'],
            'amount' => $amount,
        ]);
    }

    /** Close the day the way the employee does, then settle it. */
    private function close(DailyCommitment $commitment): void
    {
        $commitment->forceFill(['submitted_at' => now()])->save();

        $this->service->syncCommitment($commitment->refresh());
    }

    private function approvedCase(float $amount): Customer
    {
        return Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'approved',
            'eligibility_status' => 'eligible',
            'documentation_status' => 'complete',
            'approved_loan_amount' => $amount,
            'sanctioned_loan_amount' => null,
            'approval_date' => today(),
            'disbursal_date' => null,
            'disbursal_status' => null,
            'disbursal_finalized' => false,
        ]);
    }

    private function sflCase(float $amount): Customer
    {
        return Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'sfl',
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
}
