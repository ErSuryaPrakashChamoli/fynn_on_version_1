<?php

namespace Tests\Feature;

use App\Models\OtherBankIncentiveSlab;
use App\Models\OtherBankSupportTarget;
use App\Models\User;
use App\Services\OtherBankSupportService;
use Database\Seeders\OtherBankSupportSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The seeder has to be safe to run against the live database, which already
 * has roles and live data: it adds the role when missing and changes nothing
 * else, however many times it is run.
 */
class OtherBankSupportSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_role_once_however_often_it_runs(): void
    {
        // The module's own migration already creates the role, so on a
        // migrated server the seeder is a second, harmless safety net.
        $this->assertSame(1, Role::query()->where('name', OtherBankSupportService::ROLE)->count());

        Role::query()->where('name', OtherBankSupportService::ROLE)->delete();

        $this->seed(OtherBankSupportSeeder::class);
        $this->seed(OtherBankSupportSeeder::class);
        $this->seed(OtherBankSupportSeeder::class);

        $this->assertSame(1, Role::query()->where('name', OtherBankSupportService::ROLE)->count());
        $this->assertSame('web', Role::query()->where('name', OtherBankSupportService::ROLE)->value('guard_name'));
    }

    public function test_it_leaves_an_existing_role_its_users_and_its_data_untouched(): void
    {
        $existing = Role::query()->where('name', OtherBankSupportService::ROLE)->sole();

        $support = User::factory()->create();
        $support->assignRole(OtherBankSupportService::ROLE);

        $target = OtherBankSupportTarget::factory()->create(['user_id' => $support->id, 'target_amount' => 1234567]);
        $slab = OtherBankIncentiveSlab::factory()->create(['min_achievement' => 1000000, 'payout_value' => 5000]);

        $this->seed(OtherBankSupportSeeder::class);

        $this->assertSame(1, Role::query()->where('name', OtherBankSupportService::ROLE)->count());
        $this->assertSame($existing->id, Role::query()->where('name', OtherBankSupportService::ROLE)->value('id'));
        $this->assertTrue($support->fresh()->hasRole(OtherBankSupportService::ROLE));

        // No invented business figures, and nothing rewritten.
        $this->assertSame(1, OtherBankSupportTarget::query()->count());
        $this->assertSame(1, OtherBankIncentiveSlab::query()->count());
        $this->assertEquals(1234567, $target->fresh()->target_amount);
        $this->assertEquals(5000, $slab->fresh()->payout_value);
    }

    public function test_the_roles_seeder_also_carries_the_new_role(): void
    {
        $this->seed(RolesSeeder::class);
        $this->seed(RolesSeeder::class);

        $this->assertSame(1, Role::query()->where('name', OtherBankSupportService::ROLE)->count());

        foreach (['Admin', 'Manager', 'Team Leader', 'Cluster Manager', 'Business Head', 'Caller'] as $role) {
            $this->assertTrue(Role::query()->where('name', $role)->exists(), "{$role} role is missing");
        }
    }
}
