<?php

namespace Database\Seeders;

use App\Models\OtherBankIncentiveSlab;
use App\Models\OtherBankSupportTarget;
use App\Services\OtherBankSupportService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Everything the Other Bank Support module needs on a database that already
 * has roles and live data.
 *
 * Safe to run repeatedly and safe on production: it only ever adds the role
 * if it is missing, and never updates or deletes an existing row. Targets and
 * incentive slabs are deliberately NOT seeded — they are business figures the
 * Admin sets per month in the panel, and inventing them here would put wrong
 * numbers in front of the team.
 *
 *   php artisan migrate
 *   php artisan db:seed --class=OtherBankSupportSeeder
 */
class OtherBankSupportSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRole();
        $this->reportReadiness();
    }

    /**
     * The role is the whole security boundary for this module — a user only
     * reaches other-bank files because they hold it. firstOrCreate keyed on
     * name + guard, so re-running never makes a second copy and an existing
     * role keeps whatever permissions it was already given.
     */
    private function seedRole(): void
    {
        $role = Role::firstOrCreate([
            'name' => OtherBankSupportService::ROLE,
            'guard_name' => 'web',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info($role->wasRecentlyCreated
            ? "Created role '".OtherBankSupportService::ROLE."'."
            : "Role '".OtherBankSupportService::ROLE."' already present — left as it is.");
    }

    /**
     * Says plainly whether the migrations behind the module have run, so a
     * half-migrated server is obvious here rather than at the first click.
     */
    private function reportReadiness(): void
    {
        $tables = [
            'other_bank_support_remarks',
            'other_bank_support_targets',
            'other_bank_incentive_slabs',
        ];

        $missing = array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));

        if ($missing !== []) {
            $this->command?->warn('Missing table(s): '.implode(', ', $missing).'. Run `php artisan migrate` first.');

            return;
        }

        $supportUsers = app(OtherBankSupportService::class)->supportUsers()->count();

        $this->command?->info(sprintf(
            'Ready: %d active support user(s), %d monthly target(s), %d incentive slab(s).',
            $supportUsers,
            OtherBankSupportTarget::query()->count(),
            OtherBankIncentiveSlab::query()->count(),
        ));

        if ($supportUsers === 0) {
            $this->command?->warn("No user holds the '".OtherBankSupportService::ROLE."' role yet — assign it in People & Access > Users.");
        }
    }
}
