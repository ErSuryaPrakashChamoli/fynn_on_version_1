<?php

use App\Services\OtherBankSupportService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles are otherwise only seeded (RolesSeeder). This makes the new role
 * exist on every environment the moment the migration runs, without asking
 * anyone to re-run the seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::firstOrCreate([
            'name' => OtherBankSupportService::ROLE,
            'guard_name' => 'web',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()
            ->where('name', OtherBankSupportService::ROLE)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
