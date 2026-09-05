<?php

namespace Tests\Feature;

use App\Enums\CommitmentStage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MyDailyCommitment;
use App\Filament\Resources\MonthlyCommitmentTargets\MonthlyCommitmentTargetResource;
use App\Filament\Resources\MonthlyCommitmentTargets\Pages\CreateMonthlyCommitmentTarget;
use App\Livewire\MonthlyTargetPrompt;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use App\Models\User;
use App\Services\MonthlyTargetGate;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The monthly target is fixed by hand every calendar month, and until it
 * is the panel stays shut. A Manager fixes their callers, the Admin line
 * fixes Managers and Team Leaders, and everybody else is told who to
 * chase.
 */
class MonthlyTargetGateTest extends TestCase
{
    use RefreshDatabase;

    private MonthlyTargetGate $gate;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Manager', 'Team Leader', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->gate = app(MonthlyTargetGate::class);

        $this->manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'exit_status' => 'no',
        ]);

        $this->teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->manager->id,
            'exit_status' => 'no',
        ]);

        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'exit_status' => 'no',
        ]);

        // Only an employee who can actually sign in is waited on, so every
        // fixture here needs a login of its own.
        foreach ([
            [$this->manager, 'Manager'],
            [$this->teamLeader, 'Team Leader'],
            [$this->caller, 'Caller'],
        ] as [$employee, $role]) {
            User::factory()
                ->create(['employee_id' => $employee->id])
                ->assignRole($role);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Who owns whose target
    |--------------------------------------------------------------------------
    */

    public function test_a_manager_owns_their_callers_targets_and_nobody_elses(): void
    {
        $user = $this->userFor($this->manager, 'Manager');

        $this->assertSame(
            [$this->caller->id],
            $this->gate->responsibleFor($user)->pluck('id')->all(),
        );
    }

    public function test_the_admin_owns_manager_and_team_leader_targets_and_not_caller_ones(): void
    {
        $user = $this->adminUser();

        $this->assertEqualsCanonicalizing(
            [$this->manager->id, $this->teamLeader->id],
            $this->gate->responsibleFor($user)->pluck('id')->all(),
        );
    }

    public function test_a_team_leader_owns_nobodys_target(): void
    {
        $user = $this->userFor($this->teamLeader, 'Team Leader');

        $this->assertTrue($this->gate->responsibleFor($user)->isEmpty());
        $this->assertFalse($this->gate->isTargetSetter($user));
        $this->assertFalse(MonthlyCommitmentTargetResource::canAccess());
    }

    public function test_an_employee_who_has_left_is_never_waited_on(): void
    {
        $this->caller->update(['exit_status' => 'yes']);

        $user = $this->userFor($this->manager, 'Manager');

        $this->assertTrue($this->gate->responsibleFor($user)->isEmpty());
    }

    /*
    |--------------------------------------------------------------------------
    | The block itself
    |--------------------------------------------------------------------------
    */

    public function test_a_manager_is_blocked_until_every_caller_has_a_target(): void
    {
        $user = $this->userFor($this->manager, 'Manager');

        $status = $this->gate->status($user);

        $this->assertTrue($status['blocked']);
        $this->assertSame(MonthlyTargetGate::REASON_SET_TARGETS, $status['reason']);
        $this->assertSame([$this->caller->id], $status['missing']->pluck('id')->all());
    }

    public function test_a_caller_waiting_on_a_target_is_blocked_and_told_who_to_ask(): void
    {
        $user = $this->userFor($this->caller, 'Caller');

        $status = $this->gate->status($user);

        $this->assertTrue($status['blocked']);
        $this->assertSame(MonthlyTargetGate::REASON_AWAITING_TARGET, $status['reason']);
        $this->assertSame($this->manager->id, $status['setter']?->id);
    }

    public function test_nobody_is_blocked_once_the_months_targets_exist(): void
    {
        foreach ([$this->manager, $this->teamLeader, $this->caller] as $employee) {
            $this->target($employee);
        }

        foreach ([
            $this->userFor($this->manager, 'Manager'),
            $this->userFor($this->teamLeader, 'Team Leader'),
            $this->userFor($this->caller, 'Caller'),
            $this->adminUser(),
        ] as $user) {
            $this->gate->forget();
            $this->assertFalse($this->gate->isBlocked($user), $user->name.' should not be blocked');
        }
    }

    public function test_last_months_target_does_not_satisfy_this_month(): void
    {
        $this->target($this->caller, today()->startOfMonth()->subMonth());

        $this->assertTrue($this->gate->isBlocked($this->userFor($this->caller, 'Caller')));
    }

    /*
    |--------------------------------------------------------------------------
    | The rest of the panel is genuinely closed
    |--------------------------------------------------------------------------
    */

    public function test_a_blocked_caller_cannot_open_any_other_module(): void
    {
        $this->actingAs($this->userFor($this->caller, 'Caller'));

        $this->get(MyDailyCommitment::getUrl())->assertRedirect(Dashboard::getUrl());
    }

    public function test_a_blocked_manager_is_sent_to_the_monthly_target_screen(): void
    {
        $this->actingAs($this->userFor($this->manager, 'Manager'));

        $this->get(MyDailyCommitment::getUrl())
            ->assertRedirect(MonthlyCommitmentTargetResource::getUrl());
    }

    public function test_the_panel_reopens_once_the_target_is_fixed(): void
    {
        $this->target($this->caller);

        $this->actingAs($this->userFor($this->caller, 'Caller'));

        $this->get(MyDailyCommitment::getUrl())->assertSuccessful();
    }

    /*
    |--------------------------------------------------------------------------
    | The prompt
    |--------------------------------------------------------------------------
    */

    public function test_the_prompt_lets_a_manager_fix_every_caller_target_in_one_go(): void
    {
        $second = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'exit_status' => 'no',
        ]);
        User::factory()->create(['employee_id' => $second->id]);

        $this->actingAs($this->userFor($this->manager, 'Manager'));

        Livewire::test(MonthlyTargetPrompt::class)
            ->set('bulkStage', CommitmentStage::Approved->value)
            ->set('bulkAmount', '2500000')
            ->call('applyToAll')
            ->call('saveTargets');

        foreach ([$this->caller, $second] as $employee) {
            $target = MonthlyCommitmentTarget::query()
                ->where('employee_id', $employee->id)
                ->forMonth(today()->startOfMonth())
                ->firstOrFail();

            $this->assertSame(CommitmentStage::Approved, $target->stage);
            $this->assertSame(2500000.0, $target->target_amount);
        }
    }

    public function test_an_otp_target_is_saved_as_a_count_not_an_amount(): void
    {
        $this->actingAs($this->userFor($this->manager, 'Manager'));

        Livewire::test(MonthlyTargetPrompt::class)
            ->set("targets.{$this->caller->id}.stage", CommitmentStage::Otp->value)
            ->set("targets.{$this->caller->id}.count", '40')
            ->call('saveTargets');

        $target = MonthlyCommitmentTarget::query()->where('employee_id', $this->caller->id)->firstOrFail();

        $this->assertSame(CommitmentStage::Otp, $target->stage);
        $this->assertSame(40, $target->target_count);
        $this->assertSame(0.0, $target->target_amount);
    }

    public function test_the_prompt_will_not_write_a_target_outside_the_users_own_team(): void
    {
        $outsider = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'exit_status' => 'no',
        ]);
        User::factory()->create(['employee_id' => $outsider->id]);

        $this->actingAs($this->userFor($this->manager, 'Manager'));

        Livewire::test(MonthlyTargetPrompt::class)
            ->set("targets.{$outsider->id}.stage", CommitmentStage::Approved->value)
            ->set("targets.{$outsider->id}.amount", '900000')
            ->call('saveTargets');

        $this->assertDatabaseMissing('monthly_commitment_targets', [
            'employee_id' => $outsider->id,
        ]);
    }

    public function test_the_setters_side_of_the_block_renders_on_the_target_screen(): void
    {
        $this->actingAs($this->userFor($this->manager, 'Manager'));

        $this->get(MonthlyCommitmentTargetResource::getUrl())
            ->assertSuccessful()
            ->assertSee('Fix monthly targets', false)
            ->assertSee($this->caller->emp_name);
    }

    public function test_the_block_is_actually_rendered_on_the_page(): void
    {
        $this->actingAs($this->userFor($this->caller, 'Caller'));

        $this->get(Dashboard::getUrl())
            ->assertSuccessful()
            ->assertSee('target has not been fixed', false)
            ->assertSee($this->manager->emp_name);
    }

    public function test_a_second_target_for_the_same_month_is_refused_as_a_form_error(): void
    {
        $this->target($this->teamLeader);

        $this->actingAs($this->adminUser());

        Livewire::test(CreateMonthlyCommitmentTarget::class)
            ->fillForm([
                'employee_id' => $this->teamLeader->id,
                'month' => today()->startOfMonth()->toDateString(),
                'stage' => CommitmentStage::Approved->value,
                'target_amount' => 500000,
            ])
            ->call('create')
            ->assertHasFormErrors(['employee_id']);

        $this->assertDatabaseCount('monthly_commitment_targets', 1);
    }

    public function test_a_target_of_zero_is_not_a_target(): void
    {
        $this->actingAs($this->userFor($this->manager, 'Manager'));

        Livewire::test(MonthlyTargetPrompt::class)
            ->set("targets.{$this->caller->id}.stage", CommitmentStage::Approved->value)
            ->set("targets.{$this->caller->id}.amount", '0')
            ->call('saveTargets');

        $this->assertDatabaseCount('monthly_commitment_targets', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing an existing target
    |--------------------------------------------------------------------------
    */

    public function test_a_manager_cannot_rewrite_a_team_leaders_target_but_an_admin_can(): void
    {
        $target = $this->target($this->teamLeader);

        $this->actingAs($this->userFor($this->manager, 'Manager'));
        $this->assertFalse(MonthlyCommitmentTargetResource::canEdit($target));

        $this->actingAs($this->adminUser());
        app(MonthlyTargetGate::class)->forget();
        $this->assertTrue(MonthlyCommitmentTargetResource::canEdit($target));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function userFor(Employee $employee, string $role): User
    {
        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        app(MonthlyTargetGate::class)->forget();

        return $user;
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        app(MonthlyTargetGate::class)->forget();

        return $user;
    }

    private function target(Employee $employee, ?Carbon $month = null): MonthlyCommitmentTarget
    {
        return MonthlyCommitmentTarget::create([
            'employee_id' => $employee->id,
            'month' => ($month ?? today()->startOfMonth())->toDateString(),
            'stage' => CommitmentStage::Approved,
            'target_amount' => 1000000,
            'target_count' => 0,
        ]);
    }
}
