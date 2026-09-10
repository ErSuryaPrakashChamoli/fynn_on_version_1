<?php

namespace Database\Seeders\Portal;

use App\Enums\TenantType;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * The two tenants the application ships with.
 *
 * Idempotent (updateOrCreate on the slug) so it is safe to re-run on an
 * environment that already has them — nothing here touches any existing
 * LMS table.
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::updateOrCreate(
            ['slug' => Tenant::PRODUCTION_SLUG],
            [
                'name' => 'FynnEdge Advisory',
                'type' => TenantType::Production,
                'is_demo' => false,
                'is_active' => true,
                'brand_name' => 'FynnEdge',
            ]
        );

        Tenant::updateOrCreate(
            ['slug' => Tenant::DEMO_SLUG],
            [
                'name' => 'FYNN-ON Demo',
                'type' => TenantType::Demo,
                'is_demo' => true,
                'is_active' => true,
                'brand_name' => 'FYNN-ON',
            ]
        );
    }
}
