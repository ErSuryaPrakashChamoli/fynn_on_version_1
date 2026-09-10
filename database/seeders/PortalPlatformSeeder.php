<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoDataSeeder;
use Database\Seeders\Portal\PortalUserSeeder;
use Database\Seeders\Portal\TenantSeeder;
use Database\Seeders\Training\TrainingContentSeeder;
use Database\Seeders\Training\TrainingDeliverySeeder;
use Illuminate\Database\Seeder;

/**
 * The one entry point for standing up the Academy and the Demo sandbox:
 *
 *     php artisan db:seed --class=PortalPlatformSeeder
 *
 * Touches nothing outside the tenants, portal_accounts, training_* and
 * demo_* tables, and creates users only through PortalAccountService
 * (which never grants a Spatie role). It is therefore safe to run on an
 * environment that already carries live LMS data.
 */
class PortalPlatformSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            PortalUserSeeder::class,
            TrainingContentSeeder::class,
            TrainingDeliverySeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
