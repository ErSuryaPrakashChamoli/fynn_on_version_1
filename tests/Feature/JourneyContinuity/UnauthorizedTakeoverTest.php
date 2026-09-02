<?php

namespace Tests\Feature\JourneyContinuity;

use App\Filament\Resources\JourneyTakeovers\JourneyTakeoverResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Scenario 7 — only Admin / Cluster Manager / Business Head can reach the
 * Emergency Takeover screen at all. The takeover service itself never
 * re-derives "who is allowed to call this" (that's the Filament resource's
 * job, exactly like every other authorization boundary in this app), so
 * this test verifies that boundary directly.
 */
class UnauthorizedTakeoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Cluster Manager', 'Business Head', 'Manager', 'Team Leader'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    public function test_manager_cannot_access_the_takeover_resource(): void
    {
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $user = User::factory()->create(['employee_id' => $manager->id]);
        $user->assignRole('Manager');
        $this->actingAs($user);

        $this->assertFalse(JourneyTakeoverResource::canAccess());
    }

    public function test_team_leader_cannot_access_the_takeover_resource(): void
    {
        $teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER]);
        $user = User::factory()->create(['employee_id' => $teamLeader->id]);
        $user->assignRole('Team Leader');
        $this->actingAs($user);

        $this->assertFalse(JourneyTakeoverResource::canAccess());
    }

    public function test_cluster_manager_can_access_the_takeover_resource(): void
    {
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $user = User::factory()->create(['employee_id' => $clusterManager->id]);
        $user->assignRole('Cluster Manager');
        $this->actingAs($user);

        $this->assertTrue(JourneyTakeoverResource::canAccess());
    }

    public function test_admin_can_access_the_takeover_resource(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);

        $this->assertTrue(JourneyTakeoverResource::canAccess());
    }
}
