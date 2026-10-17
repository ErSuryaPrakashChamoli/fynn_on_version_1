<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A freshly seeded database gets one Admin login: admin@fynnedge.com with
 * the password "admin". An admin that already exists keeps its password.
 */
class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    public function test_it_creates_the_default_admin_with_password_admin(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->where('email', 'admin@fynnedge.com')->sole();

        $this->assertSame('Admin', $admin->name);
        $this->assertTrue(Hash::check('admin', $admin->password));
        $this->assertFalse(Hash::check('Admin@123', $admin->password));
        $this->assertTrue($admin->hasRole('Admin'));
    }

    public function test_it_does_not_reset_the_password_of_an_existing_admin(): void
    {
        User::factory()->create([
            'email' => 'admin@fynnedge.com',
            'password' => Hash::make('changed-by-admin'),
        ]);

        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->where('email', 'admin@fynnedge.com')->sole();

        $this->assertTrue(Hash::check('changed-by-admin', $admin->password));
        $this->assertTrue($admin->hasRole('Admin'));
    }
}
