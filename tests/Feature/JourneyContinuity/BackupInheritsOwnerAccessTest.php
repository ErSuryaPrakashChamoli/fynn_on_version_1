<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyModule;
use App\Enums\NotificationCategory;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\CustomerEligibilityService;
use App\Services\FollowUpReminderService;
use App\Services\Journey\CustomerJourneyDelegationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A continuity backup must be able to do, on the delegated stages, what the
 * original owner's side could — not be stopped by their own role name.
 */
class BackupInheritsOwnerAccessTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller;

    private Employee $backupCaller;

    private User $managerUser;

    private User $teamLeaderUser;

    private User $backupCallerUser;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Manager', 'Team Leader', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $cluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $this->manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $cluster->id,
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
        $this->backupCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
        ]);

        $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id]);
        $this->managerUser->assignRole('Manager');

        $this->teamLeaderUser = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $this->teamLeaderUser->assignRole('Team Leader');

        $this->backupCallerUser = User::factory()->create(['employee_id' => $this->backupCaller->id]);
        $this->backupCallerUser->assignRole('Caller');
    }

    public function test_a_team_leader_backing_up_the_manager_gets_the_disbursal_step(): void
    {
        $customer = $this->sanctionedCustomer();

        $this->actingAs($this->teamLeaderUser);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertDontSee('Step 4: Disbursal Payouts');

        $this->delegate($this->manager, $this->teamLeader, [JourneyModule::DisbursalProcessing]);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertSee('Step 4: Disbursal Payouts');
    }

    public function test_a_caller_backup_can_edit_only_while_the_rule_covers_the_stage(): void
    {
        $customer = $this->customer(['journey_status' => 'underwriting']);

        $this->actingAs($this->backupCallerUser);
        $this->assertFalse(CustomerResource::canEdit($customer));

        $this->delegate($this->caller, $this->backupCaller, [JourneyModule::DocumentVerification]);
        $this->assertFalse(CustomerResource::canEdit($customer), 'A rule for another stage grants nothing here.');

        $this->delegate($this->manager, $this->backupCaller, [JourneyModule::Approval]);
        $this->assertTrue(CustomerResource::canEdit($customer->fresh()));
    }

    public function test_a_backup_can_work_eligibility_and_is_told_about_it(): void
    {
        $customer = $this->customer([
            'eligibility_status' => CustomerEligibilityService::CONSENT_PENDING,
            'journey_status' => 'not_started',
        ]);
        $service = app(CustomerEligibilityService::class);

        $this->assertFalse($service->canChangeStatus($this->backupCallerUser, $customer));

        $this->delegate($this->caller, $this->backupCaller, [JourneyModule::DocumentVerification]);

        $this->assertTrue($service->canChangeStatus($this->backupCallerUser, $customer));

        $service->changeStatus($this->managerUser, $customer, CustomerEligibilityService::NOT_ELIGIBLE, 'company_not_listed', 'Company not on the list.');

        $this->assertSame(1, $this->backupCallerUser->notifications()->where('category', NotificationCategory::Eligibility->value)->count());
    }

    public function test_a_follow_up_backup_is_reminded_and_sees_the_history(): void
    {
        $customer = $this->customer();
        $followUp = FollowUp::factory()->create([
            'customer_id' => $customer->id,
            'employee_id' => $this->caller->id,
            'next_follow_up_date' => now()->addMinutes(5),
        ]);

        $this->actingAs($this->backupCallerUser);
        $this->assertFalse(FollowUpResource::getEloquentQuery()->whereKey($followUp->id)->exists());

        $this->delegate($this->caller, $this->backupCaller, [JourneyModule::CustomerFollowUp, JourneyModule::DocumentVerification]);

        $this->assertTrue(FollowUpResource::getEloquentQuery()->whereKey($followUp->id)->exists());

        app(FollowUpReminderService::class)->sendDueReminders();

        $this->assertSame(1, $this->backupCallerUser->notifications()->count());
    }

    /**
     * @param  list<JourneyModule>  $modules
     */
    private function delegate(Employee $original, Employee $backup, array $modules): void
    {
        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $original->id,
            'acting_manager_id' => $backup->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'modules' => array_map(fn (JourneyModule $module): string => $module->value, $modules),
            'reason' => 'On leave this week.',
        ], $this->managerUser);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
            ...$attributes,
        ]);
    }

    private function sanctionedCustomer(): Customer
    {
        return $this->customer([
            'eligibility_status' => 'eligible',
            'bank_eligible_for' => 'ABFL',
            'journey_status' => 'sanctioned',
            'underwriting_status' => 'approved',
            'credit_approval_completed' => true,
            'sanctioned_bank' => 'ABFL',
        ]);
    }
}
