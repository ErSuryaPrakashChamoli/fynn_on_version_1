<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerReassignments\CustomerReassignmentResource;
use App\Filament\Resources\CustomerReassignments\Pages\ListCustomerReassignments;
use App\Filament\Resources\CustomerSlaBreaches\CustomerSlaBreachResource;
use App\Filament\Resources\JourneyTakeovers\Pages\ListJourneyTakeovers;
use App\Filament\Resources\PendingManagerCases\PendingManagerCaseResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Customer;
use App\Models\CustomerReassignment;
use App\Models\CustomerSlaBreach;
use App\Models\Employee;
use App\Models\JourneyTakeover;
use App\Models\User;
use App\Services\ReportingLineService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The oversight screens a Cluster Manager (or Business Head) opens used to
 * list every branch in the company. They now show the viewer's own branch;
 * only the Admin sees everything.
 *
 * Cluster Manager CM ── Manager M ── Team Leader TL ── Caller C   (customer A)
 * Cluster Manager CM2 ── Manager M2 ── Team Leader TL2 ── Caller C2 (customer B)
 */
class BranchScopedOversightTest extends TestCase
{
    use RefreshDatabase;

    private Employee $cluster;

    private Employee $manager;

    private Employee $otherManager;

    private Customer $ownCustomer;

    private Customer $otherCustomer;

    private User $clusterLogin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Cluster Manager', 'Manager', 'IT'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$this->cluster, $this->manager, $caller] = $this->branch();
        [, $this->otherManager, $otherCaller] = $this->branch();

        $this->ownCustomer = $this->activeCustomer($caller);
        $this->otherCustomer = $this->activeCustomer($otherCaller);

        $this->clusterLogin = User::factory()->create(['employee_id' => $this->cluster->id])->assignRole('Cluster Manager');
    }

    /**
     * @return array{0: Employee, 1: Employee, 2: Employee}
     */
    private function branch(): array
    {
        $lines = app(ReportingLineService::class);

        $cluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER, 'exit_status' => 'no']);
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'exit_status' => 'no', ...$lines->columnsUnder($cluster->id)]);
        $teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'exit_status' => 'no', ...$lines->columnsUnder($manager->id)]);
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no', ...$lines->columnsUnder($teamLeader->id)]);

        return [$cluster, $manager, $caller];
    }

    private function activeCustomer(Employee $owner): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $owner->id,
            'employee_id' => $owner->id,
            'journey_status' => 'sfl',
            'documents_submitted' => false,
        ]);
    }

    private function adminLogin(): User
    {
        return User::factory()->create()->assignRole('Admin');
    }

    public function test_pending_manager_cases_show_only_the_viewers_branch(): void
    {
        $this->actingAs($this->clusterLogin);

        $visible = PendingManagerCaseResource::getEloquentQuery()->pluck('customers.id');

        $this->assertTrue($visible->contains($this->ownCustomer->id));
        $this->assertFalse($visible->contains($this->otherCustomer->id));

        $this->actingAs($this->adminLogin());

        $this->assertTrue(PendingManagerCaseResource::getEloquentQuery()->pluck('customers.id')->contains($this->otherCustomer->id));
    }

    public function test_sla_breaches_show_the_viewers_branch_and_anything_escalated_to_them(): void
    {
        $breach = fn (Customer $customer, ?int $escalatedTo = null): CustomerSlaBreach => CustomerSlaBreach::query()->create([
            'customer_id' => $customer->id,
            'module' => 'document_verification',
            'stage_entered_at' => now()->subHours(3),
            'reminder_sent_at' => now()->subHours(2),
            'escalated_to_employee_id' => $escalatedTo,
            'status' => CustomerSlaBreach::STATUS_OPEN,
        ]);

        $own = $breach($this->ownCustomer);
        $other = $breach($this->otherCustomer);
        $escalatedToViewer = $breach($this->otherCustomer, $this->cluster->id);

        $this->actingAs($this->clusterLogin);

        $visible = CustomerSlaBreachResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($visible->contains($own->id));
        $this->assertTrue($visible->contains($escalatedToViewer->id));
        $this->assertFalse($visible->contains($other->id));
    }

    public function test_reassignment_history_shows_only_the_viewers_branch(): void
    {
        $record = fn (Customer $customer, Employee $newOwner): CustomerReassignment => CustomerReassignment::query()->create([
            'customer_id' => $customer->id,
            'previous_owner_id' => $customer->assign_to,
            'new_owner_id' => $newOwner->id,
            'reassigned_by' => $this->clusterLogin->id,
            'reason' => 'Rebalancing.',
            'reassigned_at' => now(),
        ]);

        $own = $record($this->ownCustomer, $this->manager);
        $other = $record($this->otherCustomer, $this->otherManager);

        $this->actingAs($this->clusterLogin);

        $visible = CustomerReassignmentResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($visible->contains($own->id));
        $this->assertFalse($visible->contains($other->id));
    }

    public function test_a_cluster_manager_cannot_reassign_a_customer_from_another_branch(): void
    {
        $this->actingAs($this->clusterLogin);

        Livewire::test(ListCustomerReassignments::class)
            ->callAction(TestAction::make('reassignCustomer')->table(), data: [
                'customer_id' => $this->otherCustomer->id,
                'new_owner_id' => $this->manager->id,
                'reason' => 'Trying to pull a case across branches.',
            ]);

        $this->assertSame($this->otherCustomer->assign_to, $this->otherCustomer->fresh()->assign_to);
        $this->assertSame(0, CustomerReassignment::query()->count());
    }

    public function test_a_cluster_manager_can_reassign_inside_their_own_branch(): void
    {
        $this->actingAs($this->clusterLogin);

        Livewire::test(ListCustomerReassignments::class)
            ->callAction(TestAction::make('reassignCustomer')->table(), data: [
                'customer_id' => $this->ownCustomer->id,
                'new_owner_id' => $this->manager->id,
                'reason' => 'Team Leader on leave.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($this->manager->id, $this->ownCustomer->fresh()->assign_to);
    }

    public function test_the_users_screen_is_closed_to_everyone_but_admin_and_it(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->manager->id])->assignRole('Manager'));
        $this->assertFalse(UserResource::canAccess());

        $this->actingAs(User::factory()->create()->assignRole('IT'));
        $this->assertTrue(UserResource::canAccess());

        $this->actingAs($this->adminLogin());
        $this->assertTrue(UserResource::canAccess());
    }

    public function test_a_cluster_manager_cannot_take_over_a_customer_from_another_branch(): void
    {
        $this->actingAs($this->clusterLogin);

        Livewire::test(ListJourneyTakeovers::class)
            ->callAction(TestAction::make('takeOverJourney')->table(), data: [
                'customer_id' => $this->otherCustomer->id,
                'takeover_type' => 'emergency',
                'reason' => 'Trying to grab a case from another branch.',
            ]);

        $this->assertSame(0, JourneyTakeover::query()->count());
    }

    public function test_a_cluster_manager_can_take_over_inside_their_own_branch(): void
    {
        $this->actingAs($this->clusterLogin);

        Livewire::test(ListJourneyTakeovers::class)
            ->callAction(TestAction::make('takeOverJourney')->table(), data: [
                'customer_id' => $this->ownCustomer->id,
                'takeover_type' => 'emergency',
                'reason' => 'Manager unreachable before the SLA expires.',
            ])
            ->assertHasNoActionErrors();

        $takeover = JourneyTakeover::query()->sole();

        $this->assertSame($this->ownCustomer->id, $takeover->customer_id);
        $this->assertSame($this->cluster->id, $takeover->takeover_by_id);
    }
}
