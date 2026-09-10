<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Support\Portal\PortalAudit;
use Database\Seeders\Demo\DemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Restores the sandbox to its shipped state.
 *
 * The safety property is structural rather than procedural: the only
 * tables this class will ever touch are the ones listed in TABLES, every
 * one of which is a demo_ table that no production code reads or writes.
 * assertDemoOnly() re-checks that at runtime, so a table added to the
 * list by mistake stops the reset rather than truncating live data.
 *
 * Ordered child-first so the foreign keys hold during the delete without
 * needing to disable constraint checks globally.
 */
class DemoResetService
{
    /**
     * @var list<string>
     */
    public const TABLES = [
        'demo_follow_ups',
        'demo_applications',
        'demo_customers',
        'demo_leads',
        'demo_employees',
        'demo_loan_products',
        'demo_banks',
    ];

    /**
     * Wipe and reseed the sandbox for one tenant.
     *
     * @return array<string, int> row counts after reseeding
     */
    public function reset(Tenant $tenant): array
    {
        if (! $tenant->isDemo()) {
            throw new RuntimeException(
                "Refusing to reset tenant [{$tenant->slug}] — it is not a demo tenant."
            );
        }

        $this->assertDemoOnly();

        DB::transaction(function () use ($tenant): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->where('tenant_id', $tenant->getKey())->delete();
            }
        });

        app(DemoDataSeeder::class)->seedFor($tenant);

        $counts = $this->counts($tenant);

        PortalAudit::demoReset($tenant, $counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function counts(Tenant $tenant): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->where('tenant_id', $tenant->getKey())->count();
        }

        return $counts;
    }

    /**
     * Every table this service may delete from must be a demo_ table
     * that actually exists. Anything else is a programming error, and
     * finding out here is much cheaper than finding out afterwards.
     */
    protected function assertDemoOnly(): void
    {
        foreach (self::TABLES as $table) {
            if (! str_starts_with($table, 'demo_')) {
                throw new RuntimeException(
                    "Refusing to reset [{$table}] — only demo_ tables may be reset."
                );
            }

            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Demo table [{$table}] does not exist.");
            }
        }
    }
}
