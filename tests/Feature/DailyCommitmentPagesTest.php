<?php

namespace Tests\Feature;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Filament\Pages\DailyCommitmentDashboard;
use App\Filament\Pages\DailyCommitmentDetail;
use App\Filament\Pages\DailyCommitmentReports;
use App\Filament\Pages\DailyCommitmentTeamView;
use App\Filament\Pages\MyDailyCommitment;
use App\Filament\Resources\MonthlyCommitmentTargets\MonthlyCommitmentTargetResource;
use App\Filament\Resources\MonthlyCommitmentTargets\Pages\ListMonthlyCommitmentTargets;
use App\Models\Customer;
use App\Models\DailyCallerOtp;
use App\Models\DailyCommitment;
use App\Models\DailyCommitmentEntry;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use App\Models\User;
use App\Services\DailyCommitmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every screen in the Daily Commitment module renders, the commitment
 * form actually saves, and the module's access rules hold server-side.
 */
class DailyCommitmentPagesTest extends TestCase
{
    use RefreshDatabase;

    private Employee $teamLeader;

    private Employee $caller;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Team Leader', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
        ]);

        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
        ]);
    }

    public function test_every_page_renders_for_an_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(DailyCommitmentDashboard::class)->assertOk();
        Livewire::test(DailyCommitmentTeamView::class)->assertOk();
        Livewire::test(DailyCommitmentReports::class)->assertOk();
        Livewire::test(ListMonthlyCommitmentTargets::class)->assertOk();
    }

    public function test_a_commitment_is_locked_once_given_and_cannot_be_changed_by_its_owner(): void
    {
        $user = User::factory()->create(['employee_id' => $this->caller->id]);
        $user->assignRole('Caller');
        $this->actingAs($user);

        Livewire::test(MyDailyCommitment::class)
            ->fillForm([
                'commitment_stage' => CommitmentStage::Approved->value,
                'commitment_amount' => 1000000,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('daily_commitments', [
            'employee_id' => $this->caller->id,
            'commitment_stage' => CommitmentStage::Approved->value,
            'commitment_amount' => 1000000,
        ]);

        // A second attempt — including one that bypasses the disabled
        // fields — must not move the number.
        Livewire::test(MyDailyCommitment::class)
            ->assertSee('locked')
            ->set('data.commitment_stage', CommitmentStage::Disbursed->value)
            ->set('data.commitment_amount', 100)
            ->call('save');

        $commitment = DailyCommitment::query()->where('employee_id', $this->caller->id)->firstOrFail();

        $this->assertSame(CommitmentStage::Approved, $commitment->commitment_stage);
        $this->assertSame(1000000.0, $commitment->commitment_amount);
        $this->assertDatabaseCount('daily_commitments', 1);
        $this->assertDatabaseMissing('daily_commitment_logs', ['change_type' => 'commitment']);
    }

    public function test_a_team_leader_cannot_change_a_callers_commitment_either(): void
    {
        $commitment = DailyCommitment::create([
            'employee_id' => $this->teamLeader->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
        ]);

        $user = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $user->assignRole('Team Leader');
        $this->actingAs($user);

        $this->assertFalse($commitment->isEditableBy($user));

        Livewire::test(DailyCommitmentDetail::class, ['record' => $commitment->id])
            ->assertOk()
            ->assertActionHidden('editCommitment');
    }

    public function test_an_admin_can_correct_a_locked_commitment_and_the_change_is_logged(): void
    {
        $commitment = DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(DailyCommitmentDetail::class, ['record' => $commitment->id])
            ->assertActionVisible('editCommitment')
            ->callAction('editCommitment', [
                'commitment_stage' => CommitmentStage::Disbursed->value,
                'commitment_amount' => 500000,
                'note' => 'Entered against the wrong stage.',
            ]);

        $commitment->refresh();

        $this->assertSame(CommitmentStage::Disbursed, $commitment->commitment_stage);
        $this->assertSame(500000.0, $commitment->commitment_amount);
        $this->assertDatabaseHas('daily_commitment_logs', [
            'daily_commitment_id' => $commitment->id,
            'old_stage' => CommitmentStage::Approved->value,
            'new_stage' => CommitmentStage::Disbursed->value,
            'change_type' => 'admin_correction',
            'note' => 'Entered against the wrong stage.',
        ]);
    }

    public function test_the_detail_page_shows_the_customers_and_the_change_log(): void
    {
        $commitment = DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
        ]);

        $customer = Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'customer_name' => 'Rajesh Kumar',
            'journey_status' => 'approved',
            'eligibility_status' => 'eligible',
            'approved_loan_amount' => 400000,
            'approval_date' => today(),
        ]);

        DailyCommitmentEntry::create([
            'daily_commitment_id' => $commitment->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Rajesh Kumar',
            'reference' => 'FA260905000001',
            'stage' => CommitmentStage::Approved,
            'lms_highest_stage' => CommitmentStage::Approved,
            'amount' => 400000,
        ]);

        app(DailyCommitmentService::class)->syncCommitment($commitment);

        $user = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $user->assignRole('Team Leader');
        $this->actingAs($user);

        Livewire::test(DailyCommitmentDetail::class, ['record' => $commitment->id])
            ->assertOk()
            ->assertSee($this->caller->emp_name)
            ->assertSee('Rajesh Kumar')
            ->assertSee('FA260905000001')
            ->assertSee('Customers declared')
            ->assertSee('Change log')
            ->assertSee('Achievement recalculated from the declared fulfilment.')
            ->assertSee('₹4 L');
    }

    public function test_the_detail_page_refuses_a_commitment_outside_your_hierarchy(): void
    {
        $stranger = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        $commitment = DailyCommitment::create([
            'employee_id' => $stranger->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
        ]);

        $user = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $user->assignRole('Team Leader');
        $this->actingAs($user);

        Livewire::test(DailyCommitmentDetail::class, ['record' => $commitment->id])
            ->assertForbidden();
    }

    public function test_a_team_leader_sees_their_own_callers_on_the_team_view(): void
    {
        $strangerCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        $user = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $user->assignRole('Team Leader');
        $this->actingAs($user);

        Livewire::test(DailyCommitmentTeamView::class)
            ->assertOk()
            ->assertSee($this->caller->emp_name)
            ->assertDontSee($strangerCaller->emp_name);
    }

    public function test_a_team_leader_cannot_drill_into_a_team_outside_their_hierarchy(): void
    {
        $strangerLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
        ]);

        $user = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $user->assignRole('Team Leader');
        $this->actingAs($user);

        Livewire::test(DailyCommitmentTeamView::class)
            ->call('focusOn', $strangerLeader->id)
            ->assertSet('focusId', $this->teamLeader->id);
    }

    public function test_a_caller_cannot_open_the_team_view_or_set_monthly_targets(): void
    {
        $user = User::factory()->create(['employee_id' => $this->caller->id]);
        $user->assignRole('Caller');
        $this->actingAs($user);

        $this->assertFalse(DailyCommitmentTeamView::canAccess());
        $this->assertFalse(
            MonthlyCommitmentTargetResource::canAccess()
        );
        $this->assertTrue(MyDailyCommitment::canAccess());
    }

    public function test_a_team_leader_can_set_a_callers_expected_otp(): void
    {
        $user = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $user->assignRole('Team Leader');
        $this->actingAs($user);

        Livewire::test(DailyCommitmentTeamView::class)
            ->callAction('setExpectedOtp', ['expected_otp' => 15], arguments: ['employee' => $this->caller->id]);

        // Asserted through the model so the check does not depend on how
        // the driver renders a DATE column.
        $this->assertSame(
            15,
            DailyCallerOtp::query()
                ->where('employee_id', $this->caller->id)
                ->forDate(today())
                ->value('expected_otp'),
        );
    }

    public function test_the_dashboard_shows_a_committed_caller_with_their_achievement(): void
    {
        $commitment = DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
        ]);

        $customer = Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'approved',
            'eligibility_status' => 'eligible',
            'approved_loan_amount' => 800000,
            'approval_date' => today(),
            'disbursal_status' => null,
            'disbursal_finalized' => false,
        ]);

        DailyCommitmentEntry::create([
            'daily_commitment_id' => $commitment->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'stage' => CommitmentStage::Approved,
            'lms_highest_stage' => CommitmentStage::Approved,
            'amount' => 800000,
        ]);

        DailyCallerOtp::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'expected_otp' => 10,
        ]);

        $user = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $user->assignRole('Team Leader');
        $this->actingAs($user);

        Livewire::test(DailyCommitmentDashboard::class)
            ->assertOk()
            ->assertSee($this->caller->emp_name)
            ->assertSee('₹8 L')
            ->assertSee('80%');
    }

    public function test_the_dashboard_table_defaults_to_people_who_committed(): void
    {
        $uncommittedCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
        ]);

        DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
        ]);

        $user = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $user->assignRole('Team Leader');
        $this->actingAs($user);

        // Asserted on the computed rows rather than the rendered HTML —
        // every employee's name also appears in the filter dropdowns.
        $listed = function (Testable $page): array {
            return collect($page->instance()->tableRows)
                ->pluck('employee.id')
                ->all();
        };

        $page = Livewire::test(DailyCommitmentDashboard::class);
        $this->assertSame([$this->caller->id], $listed($page));

        // The team leader is in their own visible set and has not
        // committed either, so they appear here alongside the caller.
        $page->set('data.show', 'not_committed');
        $this->assertEqualsCanonicalizing(
            [$this->teamLeader->id, $uncommittedCaller->id],
            $listed($page),
        );

        $page->set('data.show', 'all');
        $this->assertEqualsCanonicalizing(
            [$this->teamLeader->id, $this->caller->id, $uncommittedCaller->id],
            $listed($page),
        );
    }

    public function test_my_commitment_shows_the_live_position_and_the_monthly_block(): void
    {
        MonthlyCommitmentTarget::create([
            'employee_id' => $this->caller->id,
            'month' => today()->startOfMonth(),
            'stage' => CommitmentStage::Approved,
            'target_amount' => 2000000,
        ]);

        $commitment = DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
        ]);

        $customer = Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'approved',
            'eligibility_status' => 'eligible',
            'approved_loan_amount' => 800000,
            'approval_date' => today(),
            'disbursal_status' => null,
            'disbursal_finalized' => false,
        ]);

        DailyCommitmentEntry::create([
            'daily_commitment_id' => $commitment->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'stage' => CommitmentStage::Approved,
            'lms_highest_stage' => CommitmentStage::Approved,
            'amount' => 800000,
        ]);

        app(DailyCommitmentService::class)->syncCommitment($commitment);

        $user = User::factory()->create(['employee_id' => $this->caller->id]);
        $user->assignRole('Caller');
        $this->actingAs($user);

        Livewire::test(MyDailyCommitment::class)
            ->assertOk()
            ->assertSee('Final status / fulfilment')
            ->assertSee('Current pipeline')
            ->assertSee('Month to date')
            ->assertSee($customer->customer_name)
            ->assertSee('₹8 L')
            ->assertSee('80%');
    }

    public function test_an_otp_commitment_uses_the_count_field(): void
    {
        $user = User::factory()->create(['employee_id' => $this->caller->id]);
        $user->assignRole('Caller');
        $this->actingAs($user);

        Livewire::test(MyDailyCommitment::class)
            ->fillForm([
                'commitment_stage' => CommitmentStage::Otp->value,
                'commitment_count' => 20,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('daily_commitments', [
            'employee_id' => $this->caller->id,
            'commitment_stage' => CommitmentStage::Otp->value,
            'commitment_count' => 20,
            'commitment_amount' => 0,
        ]);
    }

    public function test_the_settle_command_freezes_a_past_days_result(): void
    {
        $commitment = DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today()->subDay(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
        ]);

        $this->artisan('daily-commitment:settle')->assertSuccessful();

        $this->assertSame(
            CommitmentResult::Failed,
            $commitment->refresh()->result,
        );
    }

    public function test_the_morning_commitment_form_never_asks_for_a_customer(): void
    {
        $user = User::factory()->create(['employee_id' => $this->caller->id]);
        $user->assignRole('Caller');
        $this->actingAs($user);

        $fields = array_keys(
            Livewire::test(MyDailyCommitment::class)
                ->instance()
                ->getSchema('form')
                ->getFlatFields(withHidden: true)
        );

        $this->assertEqualsCanonicalizing(
            ['date', 'commitment_stage', 'commitment_amount', 'commitment_count', 'remarks'],
            $fields,
        );

        foreach ($fields as $field) {
            $this->assertStringNotContainsString('customer', $field);
        }
    }

    public function test_an_employee_declares_customers_and_submits_the_final_status(): void
    {
        $user = User::factory()->create(['employee_id' => $this->caller->id]);
        $user->assignRole('Caller');
        $this->actingAs($user);

        $approved = Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'customer_name' => 'Rajesh Kumar',
            'journey_status' => 'approved',
            'eligibility_status' => 'eligible',
            'approved_loan_amount' => 400000,
            'approval_date' => today(),
            'disbursal_status' => null,
            'disbursal_finalized' => false,
        ]);

        $underwriting = Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'customer_name' => 'Neha Singh',
            'journey_status' => 'underwriting',
            'eligibility_status' => 'eligible',
            'eligible_loan_amount' => 200000,
            'approved_loan_amount' => null,
            'sanctioned_loan_amount' => null,
            'approval_date' => null,
            'disbursal_status' => null,
            'disbursal_finalized' => false,
        ]);

        $page = Livewire::test(MyDailyCommitment::class)
            ->fillForm([
                'commitment_stage' => CommitmentStage::Approved->value,
                'commitment_amount' => 1000000,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $page->set('fulfilment.entries', [
            ['customer_id' => $approved->id, 'customer_name' => 'Rajesh Kumar', 'reference' => null, 'stage' => CommitmentStage::Approved->value, 'outcome' => null, 'amount' => 400000, 'remarks' => null],
            ['customer_id' => $underwriting->id, 'customer_name' => 'Neha Singh', 'reference' => null, 'stage' => CommitmentStage::Underwriting->value, 'outcome' => null, 'amount' => 200000, 'remarks' => null],
        ])->call('submitFinalStatus');

        $commitment = DailyCommitment::query()->where('employee_id', $this->caller->id)->firstOrFail();

        $this->assertCount(2, $commitment->entries);
        $this->assertNotNull($commitment->submitted_at);
        // Only the Approved row is at or beyond the committed stage.
        $this->assertSame(400000.0, $commitment->achievement_amount);
        $this->assertSame(CommitmentResult::Failed, $commitment->result);
    }

    public function test_an_employee_cannot_claim_a_case_outside_their_own_book(): void
    {
        $user = User::factory()->create(['employee_id' => $this->caller->id]);
        $user->assignRole('Caller');
        $this->actingAs($user);

        $strangerCase = Customer::factory()->create([
            'employee_id' => Employee::factory()->create([
                'designation' => Employee::DESIGNATION_CALLER,
            ])->id,
            'customer_name' => 'Not Mine',
            'journey_status' => 'approved',
            'eligibility_status' => 'eligible',
            'approved_loan_amount' => 900000,
            'approval_date' => today(),
        ]);

        Livewire::test(MyDailyCommitment::class)
            ->fillForm([
                'commitment_stage' => CommitmentStage::Approved->value,
                'commitment_amount' => 1000000,
            ])
            ->call('save')
            ->set('fulfilment.entries', [
                ['customer_id' => $strangerCase->id, 'customer_name' => 'Not Mine', 'reference' => null, 'stage' => CommitmentStage::Approved->value, 'outcome' => null, 'amount' => 900000, 'remarks' => null],
            ])
            ->call('submitFinalStatus');

        $entry = DailyCommitmentEntry::query()->firstOrFail();

        // The row is kept, but never linked to a case the employee does not
        // own — so it can never inherit that case's LMS stage.
        $this->assertNull($entry->customer_id);
        $this->assertNull($entry->lms_highest_stage);
    }

    public function test_a_submitted_final_status_can_be_reopened(): void
    {
        $user = User::factory()->create(['employee_id' => $this->caller->id]);
        $user->assignRole('Caller');
        $this->actingAs($user);

        $commitment = DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
            'submitted_at' => now(),
        ]);

        Livewire::test(MyDailyCommitment::class)->call('reopenFinalStatus');

        $this->assertNull($commitment->refresh()->submitted_at);
        $this->assertSame(CommitmentResult::InProgress, $commitment->result);
    }

    public function test_the_dashboard_role_filter_and_period_dropdown_drive_the_listing(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today()->subDays(10),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 500000,
        ]);

        $listed = fn (Testable $page): array => collect($page->instance()->tableRows)
            ->pluck('employee.id')
            ->all();

        $page = Livewire::test(DailyCommitmentDashboard::class)->set('data.show', 'all');

        // Role filter narrows to one level of the hierarchy.
        $page->set('data.role', Employee::DESIGNATION_TEAM_LEADER);
        $this->assertSame([$this->teamLeader->id], $listed($page));

        $page->set('data.role', Employee::DESIGNATION_CALLER);
        $this->assertEqualsCanonicalizing([$this->caller->id], $listed($page));

        // Today has no commitment; widening the period picks the old one up.
        $page->set('data.role', null)->set('data.show', 'committed');
        $this->assertSame([], $listed($page));

        $page->set('data.range', 'last_month');
        $this->assertSame([$this->caller->id], $listed($page));
    }

    public function test_the_dashboard_stage_filter_matches_the_committed_or_the_final_stage(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $commitment = DailyCommitment::create([
            'employee_id' => $this->caller->id,
            'date' => today(),
            'commitment_stage' => CommitmentStage::Approved,
            'commitment_amount' => 1000000,
        ]);

        $customer = Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => 'sanctioned',
            'eligibility_status' => 'eligible',
            'sanctioned_loan_amount' => 1200000,
            'disbursal_status' => 'disbursed',
        ]);

        DailyCommitmentEntry::create([
            'daily_commitment_id' => $commitment->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'stage' => CommitmentStage::Disbursed,
            'lms_highest_stage' => CommitmentStage::Disbursed,
            'amount' => 1200000,
        ]);

        app(DailyCommitmentService::class)->syncCommitment($commitment);

        $listed = fn (Testable $page): array => collect($page->instance()->tableRows)
            ->pluck('employee.id')
            ->all();

        $page = Livewire::test(DailyCommitmentDashboard::class);

        $page->set('data.stage', CommitmentStage::Approved->value);
        $this->assertSame([$this->caller->id], $listed($page), 'Matches the committed stage.');

        $page->set('data.stage', CommitmentStage::Disbursed->value);
        $this->assertSame([$this->caller->id], $listed($page), 'Matches the final stage reached.');

        $page->set('data.stage', CommitmentStage::Sfl->value);
        $this->assertSame([], $listed($page));
    }
}
