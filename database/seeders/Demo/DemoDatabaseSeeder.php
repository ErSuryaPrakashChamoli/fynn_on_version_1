<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoDatabase;
use Database\Seeders\Demo\Concerns\DemoSeedState;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Entry point for seeding the DEMO database — only ever through:
 *
 *     php artisan demo:migrate --fresh --seed
 *
 * which runs it inside DemoContext with `demo` as the default connection,
 * so the ordinary application models, services and observers write to the
 * demo database. It refuses to run in any other setting: a demo connection
 * that resolves to the main database, the demo context switched off, or a
 * default connection other than `demo`.
 *
 * The dataset is built on an empty database (use --fresh to rebuild).
 * Randomness is seeded, so two rebuilds on the same day produce the same
 * shape of data; dates are relative to the day it runs.
 */
class DemoDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DemoDatabase::assertIsolated();

        if (! DemoContext::isActive()) {
            throw new RuntimeException('The demo seeder only runs inside the demo context. Use `php artisan demo:migrate --fresh --seed`.');
        }

        if (DB::getDefaultConnection() !== DemoDatabase::connectionName()) {
            throw new RuntimeException('The demo seeder refuses to run: the default connection is ['.DB::getDefaultConnection().'], not ['.DemoDatabase::connectionName().'].');
        }

        if (User::query()->exists()) {
            $this->command?->info('Demo dataset already present — skipped. Rebuild with `php artisan demo:migrate --fresh --seed`.');

            return;
        }

        fake()->seed(20260924);
        DemoSeedState::start();

        try {
            $this->call([
                DemoReferenceDataSeeder::class,
                DemoOrganisationSeeder::class,
                DemoCustomerJourneySeeder::class,
                DemoSettlementSeeder::class,
                DemoProspectingSeeder::class,
                DemoTargetsAndCommitmentsSeeder::class,
                DemoLoginSessionSeeder::class,
            ]);
        } finally {
            Carbon::setTestNow();
            Auth::forgetUser();
        }

        $this->printLogins();
    }

    protected function printLogins(): void
    {
        $this->command?->newLine();
        $this->command?->info('Demo logins (all share one password — DEMO_USER_PASSWORD, or the generated one printed above):');
        $this->command?->table(
            ['Role', 'Name', 'Email'],
            array_map(fn (array $login): array => [$login['role'], $login['name'], $login['email']], DemoSeedState::logins()),
        );
    }
}
