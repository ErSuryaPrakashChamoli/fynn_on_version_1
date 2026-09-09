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

    public function test_the_rest_of_the_lms_stays_open_while_the_prompt_is_up(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->assertTrue(app(DailyCommitmentGate::class)->isBlocked($this->user));

        // The prompt is the whole of the enforcement. A commitment module
        // has no business stopping other work, so every route stays open.
        $this->actingAs($this->user)
            ->get(DailyCommitmentDashboard::getUrl())
            ->assertOk();
    }

    public function test_my_commitment_itself_stays_reachable_while_blocked(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->actingAs($this->user)
            ->get(MyDailyCommitment::getUrl())
            ->assertSuccessful();
    }

    public function test_the_prompt_gives_an_otp_commitment(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:32'));

        $this->actingAs($this->user);

        Livewire::test(DailyCommitmentPrompt::class)
            ->set('stage', CommitmentStage::Otp->value)
            ->set('count', '1')
            ->call('giveCommitment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('daily_commitments', [
            'employee_id' => $this->caller->id,
            'commitment_stage' => CommitmentStage::Otp->value,
            'commitment_count' => 1,
        ]);
    }

    /**
     * The amount box and the OTP box occupy the same slot in the prompt.
     * Livewire morphs the DOM in place and only re-initialises Alpine on
     * elements it has just ADDED, so without a key of its own the one
     * input is reused across the swap and keeps binding to `amount` —
     * the OTP the employee typed never reaches the server, and "Give
     * commitment" then does nothing at all.
     */
    public function test_the_two_units_never_share_a_dom_node(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->actingAs($this->user);

        $prompt = Livewire::test(DailyCommitmentPrompt::class);

        $prompt->assertSee('wire:key="commit-amount"', escape: false)
            ->assertDontSee('wire:key="commit-count"', escape: false);

        $prompt->set('stage', CommitmentStage::Otp->value)
            ->assertSee('wire:key="commit-count"', escape: false)
            ->assertDontSee('wire:key="commit-amount"', escape: false);
    }

    public function test_the_stage_the_server_holds_is_the_one_the_dropdown_shows(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->actingAs($this->user);

        // Otp is option one in the list, so an unmarked <select> would show
        // it while the server was holding the default of Disbursal.
        Livewire::test(DailyCommitmentPrompt::class)
            ->assertSee('<option value="disbursed" selected>Disbursal</option>', escape: false)
            ->assertSee('<option value="otp" >No. of OTPs</option>', escape: false);
    }

    public function test_switching_the_stage_drops_the_number_typed_for_the_other_unit(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->actingAs($this->user);

        Livewire::test(DailyCommitmentPrompt::class)
            ->set('amount', '1000000')
            ->set('stage', CommitmentStage::Otp->value)
            ->assertSet('amount', null)
            ->set('count', '3')
            ->set('stage', CommitmentStage::Approved->value)
            ->assertSet('count', null);
    }

    public function test_the_prompt_says_so_rather_than_doing_nothing_when_the_number_is_missing(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->actingAs($this->user);

        Livewire::test(DailyCommitmentPrompt::class)
            ->set('stage', CommitmentStage::Otp->value)
            ->call('giveCommitment')
            ->assertHasErrors('count');

        $this->assertDatabaseCount('daily_commitments', 0);
    }

    /**
     * removeCase() re-indexes the array, so an unkeyed row would leave the
     * survivors bound to the inputs of the rows above them.
     */
    public function test_each_declared_case_row_is_keyed(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('18:45'));

        $this->commit(CommitmentStage::Approved, 1000000);

        app(DailyCommitmentGate::class)->forget();

        $this->actingAs($this->user);

        Livewire::test(DailyCommitmentPrompt::class)
            ->call('chooseMode', 'cases')
            ->call('addCase')
            ->assertSee('wire:key="case-0"', escape: false)
            ->assertSee('wire:key="case-1"', escape: false);
    }

    public function test_the_prompt_gives_the_commitment_without_leaving_the_page(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('10:15'));

        $this->actingAs($this->user);

        Livewire::test(DailyCommitmentPrompt::class)
            ->set('stage', CommitmentStage::Approved->value)
            ->set('amount', '1000000')
            ->call('giveCommitment');

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

    public function test_a_failed_day_closes_on_the_zeros_alone_without_a_reason(): void
    {
        $this->actingAs($this->user);

        $this->commit(CommitmentStage::Approved, 1000000);

        // Meeting the commitment is not compulsory — declaring the
        // outcome is. The zeros against every rung are that declaration,
        // and no justification is demanded on top of them.
        Livewire::test(MyDailyCommitment::class)
            ->call('declareNothing');

        $commitment = DailyCommitment::first();

        $this->assertNotNull($commitment->submitted_at);
        $this->assertSame(CommitmentResult::Failed, $commitment->result);
        $this->assertNull($commitment->declaration_note);
        $this->assertFalse(app(DailyCommitmentGate::class)->isBlocked($this->user->refresh()));
    }

    public function test_a_note_is_kept_on_a_failed_day_when_one_is_given(): void
    {
        $this->actingAs($this->user);

        $this->commit(CommitmentStage::Approved, 1000000);

        Livewire::test(MyDailyCommitment::class)
            ->set('nothingReason', 'Two files were pushed back by credit.')
            ->call('declareNothing');

        $this->assertStringContainsString('pushed back by credit', DailyCommitment::first()->declaration_note);
    }

    public function test_real_business_cannot_be_slipped_in_through_the_failed_day_zeros(): void
    {
        $this->actingAs($this->user);

        $this->commit(CommitmentStage::Approved, 1000000);

        // ₹7L against Approval is not a failed day, and it has no customer
        // or mobile number behind it — it belongs on a named case.
        Livewire::test(MyDailyCommitment::class)
            ->set('nilStages.'.CommitmentStage::Approved->value, '700000')
            ->call('declareNothing');

        $this->assertNull(DailyCommitment::first()->submitted_at);
        $this->assertSame(0, DailyCommitmentEntry::count());
    }

    public function test_the_customer_list_is_only_asked_for_once_there_is_something_to_feed_it(): void
    {
        $this->actingAs($this->user);

        $this->commit(CommitmentStage::Approved, 1000000);

        $page = Livewire::test(MyDailyCommitment::class);

        // Nothing chosen yet: neither the case list nor the zeros are shown.
        $this->assertNull($page->get('declarationMode'));

        $page->call('chooseDeclarationMode', 'cases');
        $this->assertSame('cases', $page->get('declarationMode'));

        $page->call('chooseDeclarationMode', 'failed');
        $this->assertSame('failed', $page->get('declarationMode'));

        // Anything else is not a path.
        $page->call('chooseDeclarationMode', 'whatever');
        $this->assertNull($page->get('declarationMode'));
    }

    /*
    |--------------------------------------------------------------------------
    | A customer is claimed once, by one person
    |--------------------------------------------------------------------------
    */

    public function test_a_case_cannot_be_declared_without_a_mobile_number(): void
    {
        $this->actingAs($this->user);

        $this->commit(CommitmentStage::Approved, 1000000);

        Livewire::test(MyDailyCommitment::class)
            ->set('fulfilment.entries', [[
                'customer_id' => null,
                'customer_name' => 'Rohit Kumar',
                'mobile_no' => null,
                'stage' => CommitmentStage::Approved->value,
                'amount' => 1000000,
            ]])
            ->call('submitFinalStatus');

        $this->assertSame(0, DailyCommitmentEntry::count());
        $this->assertNull(DailyCommitment::first()->submitted_at);
    }

    public function test_a_mobile_number_already_counted_cannot_be_counted_again(): void
    {
        // Yesterday, somebody else already claimed this customer.
        $other = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        $theirs = DailyCommitment::create([
            'employee_id' => $other->id,
            'date' => today()->subDay(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 500000,
            'result' => CommitmentResult::InProgress,
            'submitted_at' => now(),
        ]);

        DailyCommitmentEntry::create([
            'daily_commitment_id' => $theirs->id,
            'customer_name' => 'Rohit Kumar',
            'mobile_no' => '9876543210',
            'stage' => CommitmentStage::Approved,
            'amount' => 500000,
        ]);

        $this->actingAs($this->user);
        $this->commit(CommitmentStage::Approved, 1000000);

        // Same customer, typed with a country code and spacing — it is the
        // same number once normalised, and must still be refused.
        Livewire::test(MyDailyCommitment::class)
            ->set('fulfilment.entries', [[
                'customer_id' => null,
                'customer_name' => 'Rohit K',
                'mobile_no' => '+91 98765 43210',
                'stage' => CommitmentStage::Approved->value,
                'amount' => 1000000,
            ]])
            ->call('submitFinalStatus');

        $this->assertSame(1, DailyCommitmentEntry::count(), 'Only the original claim survives.');
        $this->assertNull(DailyCommitment::where('employee_id', $this->caller->id)->first()->submitted_at);
    }

    public function test_the_same_number_cannot_appear_twice_on_one_day(): void
    {
        $this->actingAs($this->user);

        $this->commit(CommitmentStage::Approved, 1000000);

        Livewire::test(MyDailyCommitment::class)
            ->set('fulfilment.entries', [
                [
                    'customer_id' => null,
                    'customer_name' => 'Rohit Kumar',
                    'mobile_no' => '9876543210',
                    'stage' => CommitmentStage::Approved->value,
                    'amount' => 500000,
                ],
                [
                    'customer_id' => null,
                    'customer_name' => 'Rohit Kumar again',
                    'mobile_no' => '09876543210',
                    'stage' => CommitmentStage::Approved->value,
                    'amount' => 500000,
                ],
            ])
            ->call('submitFinalStatus');

        $this->assertSame(0, DailyCommitmentEntry::count());
    }

    public function test_re_saving_your_own_day_is_not_a_clash_with_yourself(): void
    {
        $this->actingAs($this->user);

        $this->commit(CommitmentStage::Approved, 1000000);

        $rows = [[
            'customer_id' => null,
            'customer_name' => 'Rohit Kumar',
            'mobile_no' => '9876543210',
            'stage' => CommitmentStage::Approved->value,
            'amount' => 1000000,
        ]];

        Livewire::test(MyDailyCommitment::class)
            ->set('fulfilment.entries', $rows)
            ->call('saveFulfilment');

        $this->assertSame(1, DailyCommitmentEntry::count());

        // Saved again with the same number: the row is rebuilt, not rejected.
        Livewire::test(MyDailyCommitment::class)
            ->set('fulfilment.entries', $rows)
            ->call('submitFinalStatus');

        $this->assertSame(1, DailyCommitmentEntry::count());
        $this->assertNotNull(DailyCommitment::first()->submitted_at);
        $this->assertSame('9876543210', DailyCommitmentEntry::first()->mobile_no);
    }

    public function test_a_nil_day_still_needs_no_mobile_number(): void
    {
        $this->actingAs($this->user);

        $this->commit(CommitmentStage::Approved, 1000000);

        // Nothing was fulfilled, so there is no case and no number to give.
        Livewire::test(MyDailyCommitment::class)
            ->call('declareNothing');

        $this->assertNotNull(DailyCommitment::first()->submitted_at);
        $this->assertSame(0, DailyCommitmentEntry::count());
    }

    public function test_the_evening_prompt_closes_the_day_from_wherever_the_user_is(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('18:45'));

        $this->commit(CommitmentStage::Approved, 1000000);

        app(DailyCommitmentGate::class)->forget();

        $this->actingAs($this->user);

        Livewire::test(DailyCommitmentPrompt::class)
            ->call('chooseMode', 'cases')
            ->set('cases', [[
                'customer_name' => 'Rohit Kumar',
                'mobile_no' => '9876543210',
                'stage' => CommitmentStage::Approved->value,
                'amount' => '1000000',
            ]])
            ->call('submitCases');

        $commitment = DailyCommitment::first();

        $this->assertNotNull($commitment->submitted_at);
        $this->assertSame(1000000.0, (float) $commitment->achievement_amount);
        $this->assertSame(CommitmentResult::Met, $commitment->result);
        $this->assertSame('9876543210', DailyCommitmentEntry::first()->mobile_no);

        // Answered, so the prompt lets go — without moving the user.
        $this->assertFalse(app(DailyCommitmentGate::class)->isBlocked($this->user));
    }

    public function test_the_evening_prompt_refuses_a_case_whose_number_is_already_claimed(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('18:45'));

        $other = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        $theirs = DailyCommitment::create([
            'employee_id' => $other->id,
            'date' => today()->subDay(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 500000,
            'result' => CommitmentResult::InProgress,
            'submitted_at' => now(),
        ]);

        DailyCommitmentEntry::create([
            'daily_commitment_id' => $theirs->id,
            'customer_name' => 'Rohit Kumar',
            'mobile_no' => '9876543210',
            'stage' => CommitmentStage::Approved,
            'amount' => 500000,
        ]);

        $this->commit(CommitmentStage::Approved, 1000000);

        app(DailyCommitmentGate::class)->forget();

        $this->actingAs($this->user);

        Livewire::test(DailyCommitmentPrompt::class)
            ->call('chooseMode', 'cases')
            ->set('cases', [[
                'customer_name' => 'Rohit K',
                'mobile_no' => '+91 98765 43210',
                'stage' => CommitmentStage::Approved->value,
                'amount' => '1000000',
            ]])
            ->call('submitCases');

        $this->assertSame(1, DailyCommitmentEntry::count(), 'Only the original claim survives.');
        $this->assertNull(DailyCommitment::where('employee_id', $this->caller->id)->first()->submitted_at);
    }

    public function test_the_evening_prompt_can_record_a_failed_day(): void
    {
        Carbon::setTestNow(today()->setTimeFromTimeString('18:45'));

        $this->commit(CommitmentStage::Approved, 1000000);

        app(DailyCommitmentGate::class)->forget();

        $this->actingAs($this->user);

        Livewire::test(DailyCommitmentPrompt::class)
            ->call('chooseMode', 'failed')
            ->call('declareFailed');

        $commitment = DailyCommitment::first();

        $this->assertNotNull($commitment->submitted_at);
        $this->assertSame(CommitmentResult::Failed, $commitment->result);
        $this->assertFalse(app(DailyCommitmentGate::class)->isBlocked($this->user));
    }

    /*
    |--------------------------------------------------------------------------
    | Day-by-day history
    |--------------------------------------------------------------------------
    */

    public function test_the_history_reports_each_day_and_tallies_how_often_the_commitment_was_kept(): void
    {
        // Four closed days: one met, one overachieved, one partial, one failed.
        $this->closedDay(today()->subDays(4), 1000000, [[CommitmentStage::Approved, 1000000]]);
        $this->closedDay(today()->subDays(3), 1000000, [[CommitmentStage::Approved, 1500000]]);
        $this->closedDay(today()->subDays(2), 1000000, [[CommitmentStage::Approved, 700000], [CommitmentStage::Sfl, 300000]]);
        $this->closedDay(today()->subDay(), 1000000, []);

        $rows = $this->service->dayByDayHistory($this->caller->id, today()->subDays(10), today());

        $this->assertCount(4, $rows);
        // Newest first — a history is read backwards from today.
        $this->assertSame(today()->subDay()->toDateString(), $rows->first()['date']->toDateString());

        $tally = $this->service->historyTally($rows);

        $this->assertSame(1, $tally['met']);
        $this->assertSame(1, $tally['overachieved']);
        $this->assertSame(1, $tally['partial']);
        $this->assertSame(1, $tally['failed']);
        $this->assertSame(4, $tally['closed']);
        $this->assertSame(2, $tally['kept'], 'Met and overachieved are kept; partial and failed are not.');
        $this->assertSame(50.0, $tally['kept_percentage']);
    }

    public function test_a_day_still_running_is_not_counted_against_the_kept_rate(): void
    {
        $this->closedDay(today()->subDay(), 1000000, [[CommitmentStage::Approved, 1000000]]);

        // Today is open and unfulfilled — not yet kept, but not yet missed.
        $this->commit(CommitmentStage::Approved, 1000000);

        $tally = $this->service->historyTally(
            $this->service->dayByDayHistory($this->caller->id, today()->subDays(10), today())
        );

        $this->assertSame(2, $tally['days']);
        $this->assertSame(1, $tally['in_progress']);
        $this->assertSame(1, $tally['closed']);
        $this->assertSame(100.0, $tally['kept_percentage']);
    }

    public function test_the_history_can_be_filtered_by_result_without_moving_the_tally(): void
    {
        $this->closedDay(today()->subDays(2), 1000000, [[CommitmentStage::Approved, 1000000]]);
        $this->closedDay(today()->subDay(), 1000000, []);

        $this->actingAs($this->user);

        $page = Livewire::test(MyDailyCommitment::class)->set('historyResult', CommitmentResult::Failed->value);

        $history = $page->instance()->history;

        $this->assertCount(1, $history['filtered'], 'Only the failed day is listed.');
        $this->assertSame(CommitmentResult::Failed, $history['filtered']->first()['result']);

        // Narrowing the table must not rewrite the headline.
        $this->assertSame(2, $history['tally']['days']);
        $this->assertSame(1, $history['tally']['met']);
        $this->assertSame(1, $history['tally']['failed']);
    }

    public function test_the_history_honours_the_selected_date_range(): void
    {
        $this->closedDay(today()->subDays(40), 1000000, [[CommitmentStage::Approved, 1000000]]);
        $this->closedDay(today()->subDay(), 1000000, [[CommitmentStage::Approved, 1000000]]);

        $this->actingAs($this->user);

        $page = Livewire::test(MyDailyCommitment::class);

        // Last 7 days leaves the 40-day-old commitment out.
        $page->set('historyRange', 'last_week');
        $this->assertCount(1, $page->instance()->history['rows']);

        $page->set('historyRange', 'custom')
            ->set('historyFrom', today()->subDays(60)->toDateString())
            ->set('historyTo', today()->toDateString());

        $this->assertCount(2, $page->instance()->history['rows']);
    }

    /**
     * A commitment for $date, fulfilled by the given [stage, amount] pairs
     * and closed. Each case needs its own mobile number — they are unique
     * across the whole table.
     *
     * @param  array<int, array{0: CommitmentStage, 1: float}>  $cases
     */
    private function closedDay(Carbon $date, float $target, array $cases): DailyCommitment
    {
        $commitment = DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => $date,
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => $target,
            'result' => CommitmentResult::InProgress,
        ]);

        foreach ($cases as $index => [$stage, $amount]) {
            DailyCommitmentEntry::create([
                'daily_commitment_id' => $commitment->id,
                'customer_name' => 'Case '.$date->format('md').$index,
                'mobile_no' => '9'.str_pad((string) (crc32($date->format('Ymd').$index) % 1000000000), 9, '0', STR_PAD_LEFT),
                'stage' => $stage,
                'amount' => $amount,
            ]);
        }

        $commitment->forceFill(['submitted_at' => $date->copy()->setTime(18, 45)])->save();

        $this->service->syncCommitment($commitment->refresh());

        return $commitment;
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
