<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Demo\DemoResetService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Restores the sandbox after a prospect has been clicking around in it.
 *
 *     php artisan demo:reset
 *
 * Refuses to run against anything but a demo tenant (DemoResetService
 * checks both the tenant type and that every table it will clear is a
 * demo_ table), so there is no invocation of this command that can
 * touch production data.
 */
class ResetDemoEnvironment extends Command
{
    protected $signature = 'demo:reset
        {--tenant= : Demo tenant slug (defaults to the standard FYNN-ON demo tenant)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe and reseed the FYNN-ON demo sandbox';

    public function handle(DemoResetService $reset): int
    {
        $slug = $this->option('tenant') ?: Tenant::DEMO_SLUG;

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("No tenant found with slug [{$slug}].");

            return self::FAILURE;
        }

        if (! $tenant->isDemo()) {
            $this->error("Tenant [{$slug}] is not a demo tenant. Refusing to reset.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Reset all sandbox data for [{$tenant->name}]?", true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        try {
            $counts = $reset->reset($tenant);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Sandbox restored for [{$tenant->name}].");
        $this->table(
            ['Table', 'Rows'],
            collect($counts)->map(fn (int $count, string $table): array => [$table, $count])->values()->all(),
        );

        return self::SUCCESS;
    }
}
