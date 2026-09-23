<?php

namespace Database\Seeders\DemoEnvironment;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the client demo database: a complete, internally consistent
 * lending sales organisation placed relative to today, with one login
 * per persona in config/demo.php.
 *
 *     php artisan demo-environment:refresh --env=demo
 *
 * Every name, number and PAN is generated — nothing is copied from the
 * live database.
 */
class DemoEnvironmentSeeder extends Seeder
{
    public function run(): void
    {
        DB::disableQueryLog();

        $world = new DemoWorld;

        foreach ([
            OrganisationSeeder::class,
            CustomerJourneySeeder::class,
            EligibilitySeeder::class,
            SettlementSeeder::class,
            LeadSeeder::class,
            CommitmentSeeder::class,
            OperationsSeeder::class,
        ] as $seeder) {
            $this->callWith($seeder, ['world' => $world]);
        }

        // Top performers and gate answers are cached; start clean.
        Cache::flush();
    }
}
