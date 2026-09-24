<?php

namespace Database\Seeders\Demo;

use App\Models\Demo\DemoLead;
use App\Support\Demo\DemoDatabase;
use Illuminate\Database\Seeder;

/**
 * Entry point for seeding the DEMO database:
 *
 *     php artisan demo:migrate --seed
 *     php artisan db:seed --class="Database\Seeders\Demo\DemoDatabaseSeeder" --database=demo
 *
 * Refuses to run if the demo connection resolves to the main database.
 * The sandbox dataset is only seeded into an empty database; use
 * `php artisan demo:reset` to wipe and reseed an existing one.
 */
class DemoDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DemoDatabase::assertIsolated();

        $this->call(DemoUserSeeder::class);

        if (DemoLead::query()->exists()) {
            $this->command?->info('Demo dataset already present — skipped. Run `php artisan demo:reset` to rebuild it.');

            return;
        }

        $this->call(DemoDataSeeder::class);
    }
}
